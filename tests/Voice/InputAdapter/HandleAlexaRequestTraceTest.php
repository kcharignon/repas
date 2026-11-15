<?php

namespace Repas\Tests\Voice\InputAdapter;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Service\CommandBus;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\AlexaRequestVerifierDouble;
use Repas\Tests\Helper\ContextSpyLogger;
use Repas\Tests\Helper\ControlledVoiceTraceFeature;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\VoiceTraceInMemoryRepository;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\MockQueryBus;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealCommand;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueCommand;
use Repas\Voice\Application\Command\RecordVoiceTrace\RecordVoiceTraceCommand;
use Repas\Voice\Application\Command\RecordVoiceTrace\RecordVoiceTraceHandler;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemCommand;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealCommand;
use Repas\Voice\Application\Query\GetSpokenItemInList\GetSpokenItemInListQuery;
use Repas\Voice\Application\Query\GetSpokenItemInList\SpokenItemInListDTO;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolveAccessGrantQuery;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\Voice\Domain\Exception\SpokenMealFailure;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Domain\Service\AlexaDialogue;
use Repas\Voice\Domain\Service\AlexaTraceSanitizer;
use Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest\HandleAlexaRequestApiController;
use Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest\VoiceTraceRecorder;
use Repas\Voice\Infrastructure\Http\VoiceLinkRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * La trace des échanges avec Alexa vue du webhook (ADR 0029 décision 14) : chaque issue est gardée avec ce que le webhook en sait, jamais un jeton, jamais une
 * requête qui ne prouve pas venir d'Amazon, et jamais un échec de la trace qui change ce qu'Alexa dit. Le controller est construit à la main : le vrai bus et
 * le vrai handler de la trace, sur le dépôt en mémoire.
 */
class HandleAlexaRequestTraceTest extends TestCase
{
    private const string TOKEN = 'FACTICE-JETON-A-NE-JAMAIS-GARDER';

    private VoiceTraceInMemoryRepository $traces;
    private ContextSpyLogger $logger;
    private MockQueryBus $queryBus;
    private MockCommandBus $commandBus;

    protected function setUp(): void
    {
        $this->traces = new VoiceTraceInMemoryRepository();
        $this->logger = new ContextSpyLogger();
        $this->queryBus = new MockQueryBus();
        $this->commandBus = new MockCommandBus();
        $this->queryBus->willReturn(ResolveAccessGrantQuery::class, new ResolvedAccessGrantDTO('user-1', 'link-1', null, new DateTimeImmutable()));
    }

    public function testAnOrdinaryAnswerIsKeptWithTheAccountTheDecisionTheRequestAndTheAnswer(): void
    {
        // Given
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain.", keepSessionOpen: false, added: true));

        // When
        $this->controller()($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)->withAlexaUserId('amzn1.ask.account.FACTICE')));

        // Then
        $trace = $this->traces->last();
        $this->assertInstanceOf(VoiceTrace::class, $trace);
        $this->assertSame(['user-1', 'link-1', 'IntentRequest', 'AddItemIntent', TraceOutcome::ANSWERED, 'AddSpokenItem'], [$trace->userId, $trace->linkId, $trace->requestType, $trace->intent, $trace->outcome, $trace->decision]);
        $this->assertSame('du pain', $trace->request['request']['intent']['slots']['item']['value']);
        $this->assertSame("C'est noté : pain.", $trace->response['response']['outputSpeech']['text']);
        $this->assertNull($trace->failure);
        $this->assertGreaterThanOrEqual(0, $trace->durationMs);
    }

    public function testTheTokenAndTheAlexaUserNeverReachTheTrace(): void
    {
        // Given
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain.", keepSessionOpen: false, added: true));

        // When : le jeton arrive dans la séance ET dans le contexte du système, avec l'identifiant d'utilisateur Alexa
        $this->controller()($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)->withAlexaUserId('amzn1.ask.account.FACTICE')));

        // Then
        $trace = $this->traces->last();
        $written = (string) json_encode([$trace->request, $trace->response], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString(self::TOKEN, $written);
        $this->assertStringNotContainsString('FACTICE', $written);
        $this->assertStringNotContainsString(AlexaTestRequest::SKILL_ID, $written);
        $this->assertStringNotContainsString(self::TOKEN, $this->logger->everything());
    }

    public function testAnAnswerToAQuestionOfTheDialogueIsKeptWithTheStepItAnswered(): void
    {
        // Given : le rayon demandé, « divers » n'est pas rapproché du rayon `divers`
        $state = SpokenDialogueState::choose('ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette')->askingDepartment();
        $this->commandBus->willReturn(AnswerSpokenDialogueCommand::class, new AddSpokenItemResult('Dans quel rayon ?', keepSessionOpen: true, added: false, nextState: $state, reprompt: 'Dans quel rayon ?'));

        // When
        $this->controller()($this->request(AlexaTestRequest::answerDepartment('divers', null)->withAccessToken(self::TOKEN)->withSessionAttributes(['dialogue' => $state->toArray()])));

        // Then : l'étape, l'intention, l'entendu et son absence de résolution, l'état du dialogue de la séance, la nouvelle question
        $trace = $this->traces->last();
        $this->assertSame(['AnswerDepartmentIntent', 'AnswerSpokenDialogue:department', TraceOutcome::ANSWERED, false], [$trace->intent, $trace->decision, $trace->outcome, $trace->sessionNew]);
        $slot = $trace->request['request']['intent']['slots']['department'];
        $this->assertSame('divers', $slot['value']);
        $this->assertSame('ER_SUCCESS_NO_MATCH', $slot['resolutions']['resolutionsPerAuthority'][0]['status']['code']);
        $this->assertSame('department', $trace->request['session']['attributes']['dialogue']['step']);
        $this->assertSame('Dans quel rayon ?', $trace->response['response']['outputSpeech']['text']);
        $this->assertSame('department', $trace->response['sessionAttributes']['dialogue']['step']);
    }

    public function testTheDialogueThatAnswersAloneIsKeptAsSuch(): void
    {
        // When : « ouvre mes courses »
        $this->controller()($this->request(AlexaTestRequest::launch()->withAccessToken(self::TOKEN)));

        // Then : le dialogue a répondu sans cas d'usage
        $trace = $this->traces->last();
        $this->assertSame(['LaunchRequest', null, TraceOutcome::ANSWERED, 'AlexaDialogue'], [$trace->requestType, $trace->intent, $trace->outcome, $trace->decision]);
        $this->assertCount(1, $this->commandBus->dispatched(), 'seulement le suivi de la liaison : aucun cas d\'usage');
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, class-string, string}>
     */
    public static function useCases(): iterable
    {
        yield 'un repas à planifier' => [AlexaTestRequest::addMeal('le repas hachis parmentier')->withAccessToken(self::TOKEN), AddSpokenMealCommand::class, 'AddSpokenMeal'];
        yield 'un repas à retirer' => [AlexaTestRequest::removeMeal('le repas hachis parmentier')->withAccessToken(self::TOKEN), RemoveSpokenMealCommand::class, 'RemoveSpokenMeal'];
        yield 'un produit à retirer' => [AlexaTestRequest::removeItem('du pain')->withAccessToken(self::TOKEN), RemoveSpokenItemCommand::class, 'RemoveSpokenItem'];
        yield 'un produit à ajouter' => [AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN), AddSpokenItemCommand::class, 'AddSpokenItem'];
    }

    /**
     * @param class-string $command
     */
    #[DataProvider('useCases')]
    public function testTheDecisionIsTheUseCaseThatWasLaunched(AlexaTestRequest $request, string $command, string $decision): void
    {
        // Given
        $this->commandBus->willReturn($command, new AddSpokenItemResult('Noté.', keepSessionOpen: false, added: true));

        // When
        $this->controller()($this->request($request));

        // Then
        $this->assertSame($decision, $this->traces->last()->decision);
    }

    public function testAQuestionAboutTheListIsKeptAsAReadingDecision(): void
    {
        // Given
        $this->queryBus->willReturn(GetSpokenItemInListQuery::class, new SpokenItemInListDTO('Oui, tu en as 2.', false));

        // When
        $this->controller()($this->request(AlexaTestRequest::checkItem('du pain')->withAccessToken(self::TOKEN)));

        // Then
        $this->assertSame(['GetSpokenItemInList', TraceOutcome::ANSWERED], [$this->traces->last()->decision, $this->traces->last()->outcome]);
    }

    public function testAnUnresolvedTokenIsKeptWithoutAccountAsTheLinkAccountCard(): void
    {
        // Given : le jeton n'est résolu en rien
        $queryBus = new MockQueryBus();
        $queryBus->willReturn(ResolveAccessGrantQuery::class, null);

        // When
        $response = $this->controller(queryBus: $queryBus)($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)));

        // Then : la carte est rendue, la trace n'a ni compte ni liaison ni décision, et rien n'a été lancé
        $trace = $this->traces->last();
        $this->assertSame([null, null, TraceOutcome::ACCOUNT_NOT_LINKED, null], [$trace->userId, $trace->linkId, $trace->outcome, $trace->decision]);
        $this->assertSame('LinkAccount', $trace->response['response']['card']['type']);
        $this->assertSame('LinkAccount', json_decode((string) $response->getContent(), true)['response']['card']['type']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($trace->request));
    }

    public function testARequestWithoutATokenIsKeptAsNotLinkedToo(): void
    {
        // When
        $this->controller()($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(null)));

        // Then
        $this->assertSame(TraceOutcome::ACCOUNT_NOT_LINKED, $this->traces->last()->outcome);
    }

    public function testTheLinkRateLimitIsKeptWithTheAccountAndTheLink(): void
    {
        // Given : une liaison qui n'a droit qu'à une requête par minute
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult('Noté.', keepSessionOpen: false, added: true));
        $controller = $this->controller(linkLimit: 1);

        // When : la deuxième requête dépasse
        $controller($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)));
        $controller($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)));

        // Then
        $kept = array_values($this->traces->all());
        $this->assertCount(2, $kept);
        $this->assertSame([TraceOutcome::ANSWERED, TraceOutcome::LINK_RATE_LIMITED], [$kept[0]->outcome, $kept[1]->outcome]);
        $this->assertSame(['user-1', 'link-1', null], [$kept[1]->userId, $kept[1]->linkId, $kept[1]->decision]);
    }

    public function testASessionEndedRequestIsKeptWithAnEmptyAnswer(): void
    {
        // When
        $this->controller()($this->request(AlexaTestRequest::sessionEnded()->withAccessToken(self::TOKEN)));

        // Then
        $trace = $this->traces->last();
        $this->assertSame(['SessionEndedRequest', TraceOutcome::SESSION_ENDED, null, null], [$trace->requestType, $trace->outcome, $trace->userId, $trace->decision]);
        $this->assertSame([], $trace->response['response'], 'une réponse sans parole');
    }

    public function testAnotherLanguageIsKeptAsTheUnsupportedLanguage(): void
    {
        // When
        $this->controller()($this->request(AlexaTestRequest::launch()->withLocale('en-US')->withAccessToken(self::TOKEN)));

        // Then
        $trace = $this->traces->last();
        $this->assertSame([TraceOutcome::UNSUPPORTED_LANGUAGE, 'en-US'], [$trace->outcome, $trace->request['request']['locale']]);
    }

    public function testAFailureIsKeptWithTheAccountTheDecisionTheExceptionAndTheSentenceAlexaSaid(): void
    {
        // Given : le message de l'exception cite la phrase dite et le jeton
        $this->commandBus->willThrow(AddSpokenItemCommand::class, new RuntimeException('échec pour du pain avec ' . self::TOKEN));

        // When
        $response = $this->controller()($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)));

        // Then : la phrase d'échec est la réponse tracée ; la panne garde sa classe, son endroit et son message (jamais le journal)
        $trace = $this->traces->last();
        $this->assertSame(['user-1', 'link-1', TraceOutcome::FAILED, 'AddSpokenItem'], [$trace->userId, $trace->linkId, $trace->outcome, $trace->decision]);
        $this->assertSame(json_decode((string) $response->getContent(), true)['response']['outputSpeech']['text'], $trace->response['response']['outputSpeech']['text']);
        $this->assertSame(RuntimeException::class, $trace->failure->class);
        $this->assertMatchesRegularExpression('/^HandleAlexaRequestTraceTest\.php:\d+$/', $trace->failure->location);
        $this->assertStringContainsString('du pain', $trace->failure->message);
    }

    public function testAFailureWrappedByTheRealBusKeepsTheCauseToo(): void
    {
        // Given : le vrai bus enveloppe toute panne qui n'est pas une exception de domaine
        $bus = $this->realBusFailing(AddSpokenItemCommand::class, new RuntimeException('cause'));

        // When
        $this->controller(commandBus: $bus)($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)));

        // Then
        $trace = $this->traces->last();
        $this->assertSame(TraceOutcome::FAILED, $trace->outcome);
        $this->assertSame(RuntimeException::class, $trace->failure->previousClass);
        $this->assertSame('cause', $trace->failure->previousMessage);
    }

    public function testAKnownMealFailureIsKeptAsSuchWithTheClearSentence(): void
    {
        // Given
        $this->commandBus->willThrow(AddSpokenMealCommand::class, SpokenMealFailure::recipeGone());

        // When
        $this->controller()($this->request(AlexaTestRequest::addMeal('hachis')->withAccessToken(self::TOKEN)));

        // Then
        $trace = $this->traces->last();
        $this->assertSame([TraceOutcome::KNOWN_FAILURE, 'AddSpokenMeal', 'Je ne retrouve plus cette recette dans Repas.'], [$trace->outcome, $trace->decision, $trace->response['response']['outputSpeech']['text']]);
        $this->assertSame(SpokenMealFailure::class, $trace->failure->class);
    }

    public function testARequestThatDoesNotProveToComeFromAmazonIsNeverKept(): void
    {
        // When : le vérificateur refuse
        try {
            $this->controller(AlexaRequestVerifierDouble::refusing())($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)));
            $this->fail('La requête aurait dû être refusée.');
        } catch (VoiceException) {
            // Then : rien n'est gardé, le corps vient d'un tiers
        }
        $this->assertSame([], $this->traces->all());

        // When : le vérificateur plante, ou le corps n'est pas du JSON
        foreach ([[AlexaRequestVerifierDouble::crashing(), $this->request(AlexaTestRequest::launch())], [AlexaRequestVerifierDouble::accepting(), $this->request('pas du json')]] as [$verifier, $request]) {
            try {
                $this->controller($verifier)($request);
                $this->fail('La requête aurait dû être refusée.');
            } catch (VoiceException) {
                // Then
            }
        }
        $this->assertSame([], $this->traces->all());
    }

    public function testTheSwitchOffWritesNothingAndSendsNoCommandForTheTrace(): void
    {
        // Given
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult('Noté.', keepSessionOpen: false, added: true));

        // When
        $this->controller(traceEnabled: false)($this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)));

        // Then
        $this->assertSame([], $this->traces->all());
        $this->assertSame([], array_filter($this->commandBus->dispatched(), static fn(object $command): bool => $command instanceof RecordVoiceTraceCommand));
    }

    public function testAFailureOfTheTraceNeverChangesTheAnswerAndIsLoggedByClassOnly(): void
    {
        // Given : la trace échoue (le message de l'exception cite le jeton) ; la même requête sans trace donne la réponse de référence
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain.", keepSessionOpen: false, added: true));
        $request = $this->request(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));
        $reference = $this->controller(traceEnabled: false)($request);
        $failing = $this->realBusFailing(RecordVoiceTraceCommand::class, new RuntimeException('base en panne : ' . self::TOKEN));

        // When
        $response = $this->controller(traceBus: $failing)($request);

        // Then : la même réponse, un seul avertissement de la classe de la panne, jamais son message
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($reference->getContent(), $response->getContent());
        $warnings = array_filter($this->logger->records, static fn(array $record): bool => $record['message'] === "Trace de l'échange avec Alexa impossible.");
        $this->assertCount(1, $warnings);
        $this->assertStringNotContainsString(self::TOKEN, $this->logger->everything());
        $this->assertSame([], $this->traces->all());
    }

    /**
     * Le vrai `CommandBus` de l'application au-dessus d'un vrai bus Messenger dont le handler de `$commandClass` échoue avec `$failure`.
     */
    private function realBusFailing(string $commandClass, \Throwable $failure): CommandBusInterface
    {
        $handler = static function (object $command) use ($failure): never {
            throw $failure;
        };

        return new CommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([$commandClass => [$handler]]))]));
    }

    /**
     * Le controller : le bus des cas d'usage est un double (ou `$commandBus`), le bus de la trace est le VRAI bus avec le VRAI handler sur le dépôt en mémoire
     * (ou `$traceBus`).
     */
    private function controller(
        ?AlexaRequestVerifierDouble $verifier = null,
        ?CommandBusInterface $commandBus = null,
        ?MockQueryBus $queryBus = null,
        bool $traceEnabled = true,
        ?CommandBusInterface $traceBus = null,
        int $linkLimit = 60,
    ): HandleAlexaRequestApiController {
        $feature = new ControlledVoiceTraceFeature($traceEnabled);
        $handler = new RecordVoiceTraceHandler($this->traces, $feature, new AlexaTraceSanitizer(), new FrozenClock(new DateTimeImmutable('2026-11-15 12:00:00')));
        $traceBus ??= new CommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RecordVoiceTraceCommand::class => [$handler]]))]));

        return new HandleAlexaRequestApiController(
            $verifier ?? AlexaRequestVerifierDouble::accepting(),
            new AlexaDialogue(),
            $queryBus ?? $this->queryBus,
            $commandBus ?? $this->commandBus,
            new VoiceLinkRateLimiter(new RateLimiterFactory(['id' => 'test', 'policy' => 'sliding_window', 'limit' => $linkLimit, 'interval' => '1 minute'], new InMemoryStorage())),
            $this->logger,
            new VoiceTraceRecorder($feature, $traceBus, $this->logger),
        );
    }

    private function request(AlexaTestRequest|string $body): Request
    {
        return Request::create('/api/voice/alexa', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGNATURECERTCHAINURL' => AlexaTestRequest::CERT_CHAIN_URL,
            'HTTP_SIGNATURE_256' => 'c2lnbmF0dXJl',
        ], content: $body instanceof AlexaTestRequest ? $body->toJson() : $body);
    }
}
