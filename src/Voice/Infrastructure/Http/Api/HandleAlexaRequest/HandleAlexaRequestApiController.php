<?php

namespace Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest;


use LogicException;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Exception\SpokenMealFailure;
use Repas\Shared\Infrastructure\Http\RequiresVoice;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealCommand;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueCommand;
use Repas\Voice\Application\Command\RecordVoiceLinkUse\RecordVoiceLinkUseCommand;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemCommand;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Voice\Application\Query\GetSpokenItemInList\GetSpokenItemInListQuery;
use Repas\Voice\Application\Query\GetSpokenItemInList\SpokenItemInListDTO;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolveAccessGrantQuery;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\AlexaRequestVerifier;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\AlexaDialogue;
use Repas\Voice\Domain\Service\SpokenMealPhrase;
use Repas\Voice\Infrastructure\Http\VoiceLinkRateLimiter;
use Repas\Voice\Infrastructure\Http\VoiceRateLimit;
use Repas\Voice\Infrastructure\Http\VoiceRateLimited;
use Repas\Voice\Infrastructure\Security\OpenSslAlexaRequestVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Le webhook d'Alexa (ADR 0029, VOX-204) : « Alexa, demande à Jarvis d'ajouter du pain ».
 *
 * Route PUBLIQUE (firewall `voice`, sans sécurité, config/packages/security.yaml) : Amazon n'a pas de JWT. Ce qui la protège, dans l'ordre :
 *
 * 1. l'interrupteur `ALEXA_ENABLED` (`#[RequiresVoice]`, `404` quand il est coupé) et le plafond PAR ADRESSE IP (`#[VoiceRateLimited]`, 60 par
 *    minute), posés par des listeners AVANT ce controller : chaque requête à l'adresse de chaîne inventée coûte un téléchargement ;
 * 2. la vérification de la requête (`AlexaRequestVerifier` : adresse de la chaîne, certificat, signature du corps BRUT, horodatage à 150 s,
 *    `applicationId`). Échec, pour quelque raison que ce soit : `400` et rien d'autre. Le corps est lu UNE seule fois, borné, puis décodé
 *    tel quel : la signature porte sur ses octets exacts ;
 * 3. le jeton d'accès de la liaison de compte (`ResolveAccessGrantQuery`) : absent, inconnu, expiré, liaison retirée, compte inactif — la
 *    requête ne les distingue pas — la réponse invite à lier le compte (carte `LinkAccount`) et RIEN n'est lu ni écrit ;
 * 4. le plafond PAR LIAISON (60 par minute) : au-delà, une phrase (« Un instant… »), jamais une erreur.
 *
 * Une fois la requête authentique la réponse est TOUJOURS `200` avec une réponse vocale valide : une panne inattendue donne la phrase d'échec
 * (la classe et l'endroit de l'exception, et ceux de son exception d'origine quand le bus l'a enveloppée, sont journalisés, jamais son message
 * ni le corps, qui contient la voix transcrite). Le controller
 * traduit le protocole : la conversation est dans `AlexaDialogue`, l'ajout dans `AddSpokenItem`, la réponse à une question d'un produit inconnu
 * dans `AnswerSpokenDialogue` (l'état du dialogue voyage dans `session.attributes` à l'aller et `sessionAttributes` au retour, ADR 0029
 * décision 11), la planification d'un repas dans `AddSpokenMeal` (décision 13), son retrait dans `RemoveSpokenMeal` (décision 12, VOX-306), le suivi de la liaison dans `RecordVoiceLinkUse`, que le webhook lance en dernier et dont un échec ne change pas la réponse.
 */
#[RequiresVoice]
#[VoiceRateLimited(VoiceRateLimit::WEBHOOK)]
final class HandleAlexaRequestApiController extends AbstractController
{
    /** Profondeur JSON permise (celle que le vérificateur a déjà acceptée). */
    private const int MAX_JSON_DEPTH = 32;

    public function __construct(
        private readonly AlexaRequestVerifier $verifier,
        private readonly AlexaDialogue $dialogue,
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly VoiceLinkRateLimiter $linkRateLimiter,
        private readonly LoggerInterface $logger,
        private readonly SpokenMealPhrase $mealPhrase = new SpokenMealPhrase(),
    ) {
    }

    #[Route(path: '/api/voice/alexa', name: 'api_voice_alexa', methods: ['POST'])]
    #[OA\Post(
        summary: "Webhook du skill Alexa « Jarvis » : reçoit une requête d'Alexa et répond par une réponse vocale (route publique, appelée par Amazon)",
        description: "Le corps est la requête JSON d'un skill personnalisé (`LaunchRequest`, `IntentRequest`, `SessionEndedRequest`), signée par Amazon : les en-têtes `SignatureCertChainUrl` et `Signature-256` sont vérifiés avant tout traitement (chaîne de certificats d'Amazon, signature du corps brut, horodatage à 150 s, identifiant du skill `ALEXA_SKILL_ID`). Une requête qui ne le prouve pas reçoit `400 INVALID_ALEXA_REQUEST`, sans autre détail. Une requête authentique reçoit TOUJOURS `200` et une réponse vocale (`outputSpeech` de type `PlainText`, `shouldEndSession`) : intentions `AddItemIntent` (créneau `item` : l'article est ajouté à la liste de courses de l'utilisateur, une liste est créée s'il n'en a pas d'active ; créneau facultatif `quantity` (type `QUANTITY`, l'identifiant est le nombre, entier de 1 à 99, 1 par défaut ; un nombre inutilisable n'écrit rien et reçoit la réponse « Combien ? ») et créneau facultatif `unit` (type `UNIT`, l'identifiant est le slug de l'unité), utilisée seulement si l'ingrédient la connaît ; un article déjà dans la liste reçoit la quantité en plus et la réponse dit le total ; un produit inconnu ouvre un dialogue, des ingrédients qui se valent aussi : Alexa demande le rayon), `RemoveItemIntent` (mêmes créneaux : la quantité dite est retirée de la ligne de la liste ACTIVE, la ligne disparaît quand elle est épuisée ; jamais de liste créée, jamais de produit créé ; deux lignes qui se valent : Alexa demande le rayon), `CheckItemIntent` (créneau `item` : dit si le produit est dans la liste active et combien, lecture seule), `RemoveMealIntent` (créneau `recipe` et créneau facultatif `servings`, type `QUANTITY` : le repas planifié de la liste ACTIVE est retiré, ou son nombre de personnes baisse du nombre dit ; `RemoveItemIntent` dont la phrase commence par « repas » ou « recette » est traité de même ; un repas ou un produit absent de la liste ouvre un dialogue de trois propositions tirées de la liste, jamais de création), `AddMealIntent` (créneau `recipe` et créneau facultatif `servings`, type `QUANTITY` : la recette de l'utilisateur est planifiée dans la liste de courses, pour le nombre de personnes dit ou celui du compte ; une liste est créée s'il n'en a pas d'active ; une recette déjà dans la liste n'y est jamais deux fois, Alexa demande le nouveau total ; une recette inconnue ouvre un dialogue de trois propositions, sans jamais créer de recette ; `AddItemIntent` dont la phrase commence par « repas » ou « recette » est traité de même), `ChooseOptionIntent` (créneau `rank`, 1 à 3), `CreateItemIntent`, `AnswerDepartmentIntent` (créneau `department`), `AnswerUnitIntent` (créneau `unit`), `ListChoicesIntent` (« lesquels ? » : lit les rayons ou les unités possibles pendant le dialogue), `ChooseKindIntent` (créneau `kind` : « une recette » ou « un produit », aiguille un produit ou une recette non compris vers l'autre recherche), `AnswerServingsIntent` (créneau `servings` : le nouveau total de personnes d'un repas déjà planifié), `KeepMealIntent` (« ne change rien »), `AMAZON.YesIntent`, `AMAZON.NoIntent`, `AMAZON.HelpIntent`, `AMAZON.StopIntent`, `AMAZON.CancelIntent`, `AMAZON.FallbackIntent` ; l'état du dialogue d'un produit inconnu (trois propositions, création guidée : rayon, unité, confirmation) voyage dans les attributs de séance (`session.attributes.dialogue` à l'aller, `sessionAttributes.dialogue` au retour, rien n'est écrit en base hors l'article, le produit créé et la mémoire de phrase), lu comme une entrée non fiable ; seule la langue `fr-FR` est servie ; `SessionEndedRequest` reçoit une réponse sans parole. L'utilisateur est celui du jeton d'accès de la liaison de compte (`session.user.accessToken`, à défaut `context.System.user.accessToken`) ; sans jeton valable (absent, inconnu, expiré, liaison retirée ou compte inactif : on ne les distingue pas) la réponse porte la carte `LinkAccount`. Plafonds : 60 requêtes par minute et par adresse IP (`429`), puis 60 par minute et par liaison (réponse vocale « Un instant, réessaie dans une minute. »). Le corps (la voix transcrite) n'est jamais journalisé.",
        security: [],
        parameters: [
            new OA\Parameter(name: 'SignatureCertChainUrl', in: 'header', required: true, description: "Adresse de la chaîne de certificats d'Amazon (`https://s3.amazonaws.com/echo.api/…`)", schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Signature-256', in: 'header', required: true, description: 'Signature RSA SHA-256 du corps brut, en base64', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: "La requête d'Alexa, telle qu'Amazon l'envoie (non modélisée ici : seuls `request.type`, `request.locale`, `request.intent` (et ses créneaux), `session.new`, `session.attributes.dialogue`, `session.user` et le jeton d'accès sont lus)",
            content: new OA\JsonContent(type: 'object', example: [
                'version' => '1.0',
                'session' => ['new' => true, 'user' => ['userId' => 'amzn1.ask.account.EXAMPLE', 'accessToken' => '3Qa5mVq1xT0Zb8nWc2YpLkR7sHdJ9eUfGhAoIiNtBvM']],
                'request' => [
                    'type' => 'IntentRequest',
                    'locale' => 'fr-FR',
                    'timestamp' => '2026-10-25T12:00:00Z',
                    'intent' => ['name' => 'AddItemIntent', 'slots' => ['item' => ['name' => 'item', 'value' => 'du pain']]],
                ],
            ]),
        ),
    )]
    #[OA\Tag(name: 'Voice')]
    #[OA\Response(
        response: 200,
        description: "La réponse vocale d'Alexa (`SessionEndedRequest` : `response` vide). Avec la carte `LinkAccount` quand le compte n'est pas lié",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'version', type: 'string', example: '1.0'),
                new OA\Property(property: 'sessionAttributes', type: 'object', description: "Seulement quand la séance reste ouverte : l'état du dialogue d'un produit inconnu (`dialogue`), `{}` quand il n'y en a pas. Alexa le rend dans `session.attributes` à la requête suivante"),
                new OA\Property(property: 'response', type: 'object', properties: [
                    new OA\Property(property: 'outputSpeech', type: 'object', properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'PlainText'),
                        new OA\Property(property: 'text', type: 'string', example: "C'est noté : pain."),
                    ]),
                    new OA\Property(property: 'reprompt', type: 'object', description: "Seulement quand la séance reste ouverte : ce qu'Alexa répète après un silence"),
                    new OA\Property(property: 'card', type: 'object', description: "`{\"type\": \"LinkAccount\"}` quand le compte n'est pas lié"),
                    new OA\Property(property: 'shouldEndSession', type: 'boolean', example: true),
                ]),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: "Requête qui ne prouve pas venir d'Alexa ou ne vise pas ce skill (message `INVALID_ALEXA_REQUEST`, sans autre détail)")]
    #[OA\Response(response: 404, description: 'Commande vocale coupée (message `VOICE_DISABLED`)')]
    #[OA\Response(response: 429, ref: '#/components/responses/VoiceWebhookRateLimited')]
    public function __invoke(Request $request): JsonResponse
    {
        $alexa = AlexaRequest::fromDecoded($this->authenticDecodedBody($request));

        try {
            $response = $this->dialogue->beforeAuthentication($alexa) ?? $this->answerLinkedUser($alexa);
        } catch (Throwable $exception) {
            $context = $this->failureContext($exception);
            $known = $this->knownMealFailure($exception);
            if ($known !== null) {
                // Un échec CONNU d'un repas : une phrase claire (rien n'est écrit, le bus a tout annulé) ; la raison suffit au journal, jamais le nom de la recette
                $this->logger->warning(sprintf('Repas impossible : %s.', $known->reason), $context);
                $response = AlexaResponse::say((string) VoiceReplies::mealFailure($known->reason, $known->recipeName, $known->ingredientName));
            } else {
                $this->logger->error($this->failureMessage($context), $context);
                $response = AlexaResponse::say(VoiceReplies::FAILURE);
            }
        }

        return new JsonResponse($response->toArray());
    }

    /**
     * Ce que le journal garde d'une panne : jamais le message (il peut citer une donnée), jamais le corps ; la classe et l'endroit suffisent à
     * retrouver la panne. `CommandBus::dispatch` ne déballe que les `DomainException` : toute autre panne d'un handler arrive enveloppée dans un
     * `HandlerFailedException` dont la classe et l'endroit sont ceux de Messenger. La classe et l'endroit de l'exception d'origine
     * (`getPrevious()`) s'ajoutent donc quand elle existe.
     *
     * @return array<string, string>
     */
    private function failureContext(Throwable $exception): array
    {
        $context = ['exception' => $exception::class, 'location' => $this->placeOf($exception)];

        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $context['previous'] = $previous::class;
            $context['previous_location'] = $this->placeOf($previous);
        }

        return $context;
    }

    /**
     * L'échec connu d'un repas que cette panne porte, ou `null` : `CommandBus::dispatch` rend une `DomainException` telle quelle, mais un bus imbriqué ou
     * Messenger peut l'envelopper (`getPrevious()`), on remonte donc la chaîne. Seule une raison que `VoiceReplies::mealFailure` sait dire compte.
     */
    private function knownMealFailure(Throwable $exception): ?SpokenMealFailure
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof SpokenMealFailure && VoiceReplies::mealFailure($current->reason, $current->recipeName, $current->ingredientName) !== null) {
                return $current;
            }
        }

        return null;
    }

    /**
     * La ligne du journal de production : le contexte d'un enregistrement n'y apparaît pas (seul le message est écrit), donc la classe et l'endroit de la
     * panne, et ceux de sa cause quand le bus l'a enveloppée, sont DANS le message (2026-10-26 : quatre « Réponse à Alexa impossible. » sans rien pour
     * retrouver la panne). Jamais le message de l'exception ni le corps.
     *
     * @param array<string, string> $context
     */
    private function failureMessage(array $context): string
    {
        $message = sprintf('Réponse à Alexa impossible (%s à %s', $context['exception'], $context['location']);
        if (isset($context['previous'], $context['previous_location'])) {
            $message .= sprintf(', cause : %s à %s', $context['previous'], $context['previous_location']);
        }

        return $message . ').';
    }

    private function placeOf(Throwable $exception): string
    {
        return basename($exception->getFile()) . ':' . $exception->getLine();
    }

    /**
     * Le corps de la requête décodé, une fois PROUVÉ qu'il vient d'Alexa.
     *
     * @throws VoiceException `invalidRequest()` : quel que soit le motif, y compris une panne inattendue (dans le doute, on refuse)
     */
    private function authenticDecodedBody(Request $request): mixed
    {
        try {
            // Lu UNE seule fois, au plus un octet de plus que la limite du vérificateur : un corps géant n'est pas chargé en mémoire
            $stream = $request->getContent(true);
            $rawBody = stream_get_contents($stream, OpenSslAlexaRequestVerifier::MAX_BODY_BYTES + 1);
            if ($rawBody === false) {
                throw VoiceException::invalidRequest();
            }

            $this->verifier->verify(
                $rawBody,
                (string) $request->headers->get('SignatureCertChainUrl', ''),
                (string) $request->headers->get('Signature-256', ''),
            );

            // Le MÊME corps brut que celui dont la signature vient d'être vérifiée
            return json_decode($rawBody, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (VoiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->warning('Requête d\'Alexa illisible.', ['exception' => $exception::class]);

            throw VoiceException::invalidRequest();
        }
    }

    /**
     * La réponse pour un utilisateur lié, ou l'invitation à lier son compte. Le suivi de la liaison passe en dernier.
     */
    private function answerLinkedUser(AlexaRequest $alexa): AlexaResponse
    {
        $grant = $alexa->accessToken === null ? null : $this->queryBus->ask(new ResolveAccessGrantQuery($alexa->accessToken));
        if (!$grant instanceof ResolvedAccessGrantDTO) {
            return AlexaResponse::linkAccount(VoiceReplies::LINK_ACCOUNT);
        }

        if (!$this->linkRateLimiter->allows($grant->linkId)) {
            return AlexaResponse::say(VoiceReplies::SLOW_DOWN);
        }

        $response = $this->dialogue->answer($alexa) ?? $this->useCase($alexa, $grant);
        $this->recordUse($alexa, $grant);

        return $response;
    }

    /**
     * Ce que le dialogue n'a pas pu répondre seul : la réponse à une question en cours si la séance porte un état, sinon la planification d'un repas,
     * l'ajout d'un article, son retrait (écriture) ou la question « est-ce qu'il y en a dans la liste ? » (lecture).
     */
    private function useCase(AlexaRequest $alexa, ResolvedAccessGrantDTO $grant): AlexaResponse
    {
        if ($alexa->dialogueState !== null) {
            return $this->spoken($this->commandBus->dispatch(new AnswerSpokenDialogueCommand($grant->userId, $alexa, $alexa->dialogueState)));
        }

        // Un repas à planifier : l'intention des repas, ou un « repas » / « recette » dit à l'intention des produits (décision 13)
        $meal = $this->mealPhrase->requestOf($alexa);
        if ($meal !== null) {
            return $this->spoken($this->commandBus->dispatch(new AddSpokenMealCommand($grant->userId, $meal->recipe, $alexa->isInOpenSession(), $meal->servings, originalPhrase: $meal->original)));
        }

        // Un repas à retirer : l'intention des repas, ou un « repas » / « recette » dit à l'intention des produits (décision 12, VOX-306)
        $removal = $this->mealPhrase->removalOf($alexa);
        if ($removal !== null) {
            return $this->spoken($this->commandBus->dispatch(new RemoveSpokenMealCommand($grant->userId, $removal->recipe, $alexa->isInOpenSession(), $removal->servings, originalPhrase: $removal->original)));
        }

        $phrase = $alexa->phrase ?? throw new LogicException("Une intention sur un article sans phrase doit être redemandée par le dialogue.");

        return match ($alexa->intent()) {
            AlexaIntent::REMOVE_ITEM => $this->spoken($this->commandBus->dispatch(new RemoveSpokenItemCommand($grant->userId, $phrase, $alexa->isInOpenSession(), quantity: $alexa->quantity ?? 1, unitSlug: $alexa->unitSlug()))),
            AlexaIntent::CHECK_ITEM => $this->inList($this->queryBus->ask(new GetSpokenItemInListQuery($grant->userId, $phrase, $alexa->isInOpenSession()))),
            default => $this->spoken($this->commandBus->dispatch(new AddSpokenItemCommand($grant->userId, $phrase, $alexa->isInOpenSession(), quantity: $alexa->quantity ?? 1, unitSlug: $alexa->unitSlug()))),
        };
    }

    private function inList(mixed $answer): AlexaResponse
    {
        if (!$answer instanceof SpokenItemInListDTO) {
            throw new LogicException('La lecture de la liste doit renvoyer un SpokenItemInListDTO.');
        }

        return AlexaResponse::reply($answer->speech, $answer->keepSessionOpen, null);
    }

    private function spoken(mixed $result): AlexaResponse
    {
        if (!$result instanceof AddSpokenItemResult) {
            throw new LogicException("Le cas d'usage vocal doit renvoyer un AddSpokenItemResult.");
        }

        return AlexaResponse::reply($result->speech, $result->keepSessionOpen, $result->reprompt, $result->nextState);
    }

    /**
     * Un échec du suivi (base, course) ne change jamais ce qu'Alexa dit.
     */
    private function recordUse(AlexaRequest $alexa, ResolvedAccessGrantDTO $grant): void
    {
        try {
            $this->commandBus->dispatch(new RecordVoiceLinkUseCommand($grant->linkId, $grant->userId, $alexa->alexaUserId, $grant->alexaUserId, $grant->lastUsedAt));
        } catch (Throwable $exception) {
            $this->logger->warning('Suivi de la liaison vocale impossible.', ['exception' => $exception::class]);
        }
    }
}
