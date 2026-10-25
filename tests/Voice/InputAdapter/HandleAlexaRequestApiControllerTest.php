<?php

namespace Repas\Tests\Voice\InputAdapter;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Repas\Shared\Application\Interface\VoiceFeature;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\AlexaRequestVerifierDouble;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueCommand;
use Repas\Voice\Application\Command\RecordVoiceLinkUse\RecordVoiceLinkUseCommand;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolveAccessGrantQuery;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\Voice\Domain\Interface\AlexaRequestVerifier;

/**
 * Le webhook d'Alexa (VOX-204) avec un faux vérificateur et des faux bus : statuts, clés JSON de la réponse d'Alexa, ce qui est demandé aux
 * bus. La vérification avec de vraies signatures est dans `AlexaWebhookApiFlowTest`.
 */
class HandleAlexaRequestApiControllerTest extends QueryBusWebTestCase
{
    private const string URL = '/api/voice/alexa';
    private const string TOKEN = 'the-access-token';

    private ?AlexaRequestVerifierDouble $verifier = null;
    private MockCommandBus $commandBus;

    protected function setUp(): void
    {
        parent::setUp();

        // Le noyau reste le même d'une requête à l'autre du test : les doubles et les compteurs de débit y restent
        $this->client->disableReboot();
        $this->commandBus = $this->mockCommandBus();
    }

    public function testAnItemIsAddedAndTheAnswerIsSpokenAndTheSessionEnded(): void
    {
        // Given
        $this->linkedAccount();
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain.", keepSessionOpen: false, added: true));

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then : 200 et exactement la réponse d'un skill personnalisé
        $this->assertSame(200, $this->statusCode());
        $this->assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertSame([
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => "C'est noté : pain."],
                'shouldEndSession' => true,
            ],
        ], $this->jsonBody());
        $this->assertEquals(new ResolveAccessGrantQuery(self::TOKEN), $this->queryBus->getLastAskedQuery());
        $this->assertEquals(new AddSpokenItemCommand('user-1', 'du pain', false), $this->commandBus->dispatched()[0]);
    }

    public function testInAnOpenSessionTheSessionStaysOpenWithAReprompt(): void
    {
        // Given
        $this->linkedAccount();
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain. Autre chose ?", keepSessionOpen: true, added: true));

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)->inOpenSession());

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame([
            'version' => '1.0',
            'sessionAttributes' => [],
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => "C'est noté : pain. Autre chose ?"],
                'reprompt' => ['outputSpeech' => ['type' => 'PlainText', 'text' => 'Que veux-tu ajouter ?']],
                'shouldEndSession' => false,
            ],
        ], $this->jsonBody());
        $this->assertEquals(new AddSpokenItemCommand('user-1', 'du pain', true), $this->commandBus->dispatched()[0]);
    }

    /**
     * @return iterable<string, array{AlexaTestRequest, string, bool}>
     */
    public static function conversations(): iterable
    {
        yield 'lancement' => [AlexaTestRequest::launch(), 'Que veux-tu ajouter ?', false];
        yield 'aide' => [AlexaTestRequest::intent('AMAZON.HelpIntent'), 'Dis par exemple : ajoute du pain. Je le mets dans ta liste de courses.', false];
        yield 'stop' => [AlexaTestRequest::intent('AMAZON.StopIntent'), 'À bientôt.', true];
        yield 'annule' => [AlexaTestRequest::intent('AMAZON.CancelIntent'), 'À bientôt.', true];
        yield 'repli' => [AlexaTestRequest::intent('AMAZON.FallbackIntent'), 'Dis par exemple : ajoute du pain.', false];
        yield 'intention inconnue' => [AlexaTestRequest::intent('AMAZON.NavigateHomeIntent'), 'Dis par exemple : ajoute du pain.', false];
        yield 'article non compris' => [AlexaTestRequest::addItem(null), 'Que veux-tu ajouter ?', false];
        yield 'article « ? »' => [AlexaTestRequest::addItem('?'), 'Que veux-tu ajouter ?', false];
        yield 'type inconnu' => [AlexaTestRequest::ofType('System.ExceptionEncountered'), 'Dis par exemple : ajoute du pain.', true];
    }

    #[DataProvider('conversations')]
    public function testTheConversationIsAnsweredWithoutTouchingTheShoppingList(AlexaTestRequest $request, string $speech, bool $endsSession): void
    {
        // Given
        $this->linkedAccount();

        // When
        $this->post($request->withAccessToken(self::TOKEN));

        // Then : 200, la phrase du catalogue, aucun ajout
        $this->assertSame(200, $this->statusCode());
        $response = $this->jsonBody()['response'];
        $this->assertSame(['type' => 'PlainText', 'text' => $speech], $response['outputSpeech']);
        $this->assertSame($endsSession, $response['shouldEndSession']);
        $this->assertSame($endsSession, !isset($response['reprompt']));
        $this->assertArrayNotHasKey('card', $response);
        $this->assertSame([RecordVoiceLinkUseCommand::class], array_map(static fn(object $command): string => $command::class, $this->commandBus->dispatched()));
    }

    public function testAnUnknownProductAsksAndWritesTheDialogueInTheSessionAttributes(): void
    {
        // Given : le cas d'usage a posé la question et rend l'état du dialogue
        $this->linkedAccount();
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult(
            'Je ne connais pas ciboulette. Tu veux dire persil ? Ou un nouveau produit ?',
            keepSessionOpen: true,
            added: false,
            nextState: $state,
            reprompt: 'Tu veux dire persil ? Ou un nouveau produit ?',
        ));

        // When : un appel direct, la séance reste pourtant ouverte pour la réponse
        $this->post(AlexaTestRequest::addItem('de la ciboulette')->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame([
            'version' => '1.0',
            'sessionAttributes' => ['dialogue' => $state->toArray()],
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => 'Je ne connais pas ciboulette. Tu veux dire persil ? Ou un nouveau produit ?'],
                'reprompt' => ['outputSpeech' => ['type' => 'PlainText', 'text' => 'Tu veux dire persil ? Ou un nouveau produit ?']],
                'shouldEndSession' => false,
            ],
        ], $this->jsonBody());
    }

    public function testTheAnswerToAQuestionGoesToTheDialogueUseCaseWithTheStateTheSessionGaveBack(): void
    {
        // Given : l'état d'une réponse précédente, rendu par Alexa
        $this->linkedAccount();
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');
        $this->commandBus->willReturn(AnswerSpokenDialogueCommand::class, new AddSpokenItemResult(
            'Dans quel rayon ? Dis lesquels pour les entendre.',
            keepSessionOpen: true,
            added: false,
            nextState: $state->askingDepartment(),
            reprompt: 'Dans quel rayon ? Dis lesquels pour les entendre.',
        ));

        // When : « un nouveau produit »
        $this->post(AlexaTestRequest::createItem()->withAccessToken(self::TOKEN)->withSessionAttributes(['dialogue' => $state->toArray()]));

        // Then : la commande porte l'utilisateur du jeton, la requête et l'état lu ; la réponse écrit l'état suivant
        $command = $this->commandBus->dispatched()[0];
        $this->assertInstanceOf(AnswerSpokenDialogueCommand::class, $command);
        $this->assertSame('user-1', $command->userId);
        $this->assertEquals($state, $command->state);
        $this->assertSame('CreateItemIntent', $command->request->intentName);
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $this->jsonBody()['response']['outputSpeech']['text']);
        $this->assertSame('department', $this->jsonBody()['sessionAttributes']['dialogue']['step']);
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $this->jsonBody()['response']['reprompt']['outputSpeech']['text']);
        $this->assertFalse($this->jsonBody()['response']['shouldEndSession']);
    }

    public function testWhenTheDialogueIsOverTheAttributesAreEmptyAndTheSessionStaysOpen(): void
    {
        // Given
        $this->linkedAccount();
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');
        $this->commandBus->willReturn(AnswerSpokenDialogueCommand::class, new AddSpokenItemResult("C'est noté : persil. Je m'en souviendrai. Autre chose ?", keepSessionOpen: true, added: true));

        // When
        $this->post(AlexaTestRequest::yes()->withAccessToken(self::TOKEN)->withSessionAttributes(['dialogue' => $state->toArray()]));

        // Then : `{}` (Alexa oublie l'état) et « Que veux-tu ajouter ? » après un silence
        $this->assertStringContainsString('"sessionAttributes":{}', (string) $this->client->getResponse()->getContent());
        $this->assertSame('Que veux-tu ajouter ?', $this->jsonBody()['response']['reprompt']['outputSpeech']['text']);
    }

    public function testStopInTheMiddleOfADialogueClosesTheSessionWithoutTheUseCase(): void
    {
        // Given
        $this->linkedAccount();
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette');

        // When
        $this->post(AlexaTestRequest::intent('AMAZON.StopIntent')->withAccessToken(self::TOKEN)->withSessionAttributes(['dialogue' => $state->toArray()]));

        // Then : à bientôt, rien d'écrit à part le suivi de la liaison
        $this->assertSame(['À bientôt.', true], [$this->jsonBody()['response']['outputSpeech']['text'], $this->jsonBody()['response']['shouldEndSession']]);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertSame([RecordVoiceLinkUseCommand::class], array_map(static fn(object $command): string => $command::class, $this->commandBus->dispatched()));
    }

    public function testAnUnreadableStateIsIgnoredAndTheAnswerIsTheOneWithoutADialogue(): void
    {
        // Given : un état forgé (étape inconnue)
        $this->linkedAccount();

        // When : « un nouveau produit » sans dialogue lisible
        $this->post(AlexaTestRequest::createItem()->withAccessToken(self::TOKEN)->withSessionAttributes(['dialogue' => ['step' => 'hack', 'phrase' => 'x', 'name' => 'x', 'options' => []]]));

        // Then : le repli, aucun cas d'usage du dialogue
        $this->assertSame('Dis par exemple : ajoute du pain.', $this->jsonBody()['response']['outputSpeech']['text']);
        $this->assertSame([RecordVoiceLinkUseCommand::class], array_map(static fn(object $command): string => $command::class, $this->commandBus->dispatched()));
    }

    public function testAFailureOfTheDialogueUseCaseIsAnsweredWithASentenceAndNoState(): void
    {
        // Given : l'état portait le slug d'un autre utilisateur, ou une panne
        $this->linkedAccount();
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');
        $this->commandBus->willThrow(AnswerSpokenDialogueCommand::class, new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // When
        $this->post(AlexaTestRequest::yes()->withAccessToken(self::TOKEN)->withSessionAttributes(['dialogue' => $state->toArray()]));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->jsonBody()['response']['outputSpeech']['text']);
        $this->assertArrayNotHasKey('sessionAttributes', $this->jsonBody());
        $this->assertStringNotContainsString('persil', (string) $this->client->getResponse()->getContent());
    }

    public function testTheEndOfASessionGetsNoSpeechAndNothingIsAskedOrWritten(): void
    {
        // When
        $this->post(AlexaTestRequest::sessionEnded()->withAccessToken(self::TOKEN));

        // Then : une réponse vide
        $this->assertSame(200, $this->statusCode());
        $this->assertSame('{"version":"1.0","response":{}}', $this->client->getResponse()->getContent());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testAnotherLanguageGetsAPoliteFallbackWithoutLookingForTheAccount(): void
    {
        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)->withLocale('en-US'));

        // Then
        $this->assertSame(200, $this->statusCode());
        $response = $this->jsonBody()['response'];
        $this->assertSame('Je ne comprends que le français pour le moment.', $response['outputSpeech']['text']);
        $this->assertTrue($response['shouldEndSession']);
        $this->assertSame(0, $this->queryBus->countAskedQueries());
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testAnUnknownOrExpiredTokenInvitesToLinkTheAccountAndWritesNothing(): void
    {
        // Given : le jeton ne résout rien (inconnu, expiré, liaison retirée ou compte inactif : la requête ne le distingue pas)
        $this->queryBus->willReturn(ResolveAccessGrantQuery::class, null);

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame([
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => "Ouvre l'application Alexa pour lier ton compte Repas."],
                'card' => ['type' => 'LinkAccount'],
                'shouldEndSession' => true,
            ],
        ], $this->jsonBody());
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testNoTokenAtAllInvitesToLinkTheAccountWithoutAskingAnything(): void
    {
        foreach ([AlexaTestRequest::addItem('du pain'), AlexaTestRequest::launch(), AlexaTestRequest::intent('AMAZON.HelpIntent')] as $request) {
            // When
            $this->post($request->withAccessToken(null));

            // Then
            $this->assertSame(200, $this->statusCode());
            $this->assertSame(['type' => 'LinkAccount'], $this->jsonBody()['response']['card']);
        }
        $this->assertSame(0, $this->queryBus->countAskedQueries());
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testTheTokenCanComeFromTheContextWhenThereIsNoSession(): void
    {
        // Given
        $this->linkedAccount();

        // When
        $this->post(AlexaTestRequest::launch()->withAccessToken(self::TOKEN)->withTokenInContextOnly());

        // Then
        $this->assertSame('Que veux-tu ajouter ?', $this->jsonBody()['response']['outputSpeech']['text']);
        $this->assertEquals(new ResolveAccessGrantQuery(self::TOKEN), $this->queryBus->getLastAskedQuery());
    }

    public function testTheLinkIsTrackedWithWhatTheRequestAndTheResolutionSaidAndNeverTheTokenOrThePhrase(): void
    {
        // Given : une liaison qu'Alexa n'a jamais utilisée
        $this->linkedAccount(alexaUserId: null, lastUsedAt: null);
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain.", false, true));

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)->withAlexaUserId('amzn1.ask.account.ME'));

        // Then : l'ajout d'abord, le suivi en dernier
        $this->assertEquals(new RecordVoiceLinkUseCommand('link-1', 'user-1', 'amzn1.ask.account.ME', null, null), $this->commandBus->dispatched()[1]);
        $this->assertCount(2, $this->commandBus->dispatched());
        $this->assertStringNotContainsString(self::TOKEN, serialize($this->commandBus->dispatched()[1]));
        $this->assertStringNotContainsString('pain', serialize($this->commandBus->dispatched()[1]));
    }

    public function testTheTrackingReceivesWhatTheLinkAlreadyKnows(): void
    {
        // Given
        $lastUsedAt = new DateTimeImmutable('2026-10-25 11:00:00');
        $this->linkedAccount(alexaUserId: 'amzn1.ask.account.KNOWN', lastUsedAt: $lastUsedAt);

        // When
        $this->post(AlexaTestRequest::launch()->withAccessToken(self::TOKEN)->withAlexaUserId('amzn1.ask.account.KNOWN'));

        // Then
        $this->assertEquals(new RecordVoiceLinkUseCommand('link-1', 'user-1', 'amzn1.ask.account.KNOWN', 'amzn1.ask.account.KNOWN', $lastUsedAt), $this->commandBus->dispatched()[0]);
    }

    public function testAFailureOfTheTrackingNeverChangesTheAnswer(): void
    {
        // Given
        $this->linkedAccount();
        $this->commandBus
            ->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain.", false, true))
            ->willThrow(RecordVoiceLinkUseCommand::class, new RuntimeException('la base est tombée'));

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("C'est noté : pain.", $this->jsonBody()['response']['outputSpeech']['text']);
    }

    public function testAnUnexpectedFailureAnswersWithASentenceAndNeverATraceOrAnHttpError(): void
    {
        // Given : l'ajout échoue d'une façon inattendue ; le message de l'exception cite des données qu'on ne doit jamais renvoyer
        $this->linkedAccount();
        $this->commandBus->willThrow(AddSpokenItemCommand::class, new RuntimeException('SQLSTATE[23505] duplicate key user-1 du pain'));

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame([
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => "Je n'ai pas pu faire ça. Réessaie dans un instant."],
                'shouldEndSession' => true,
            ],
        ], $this->jsonBody());
        $this->assertStringNotContainsString('SQLSTATE', (string) $this->client->getResponse()->getContent());
        $this->assertCount(1, $this->commandBus->dispatched(), 'pas de suivi après un échec');
    }

    public function testADomainFailureOfTheAddIsAnsweredWithTheSameSentence(): void
    {
        // Given : l'ingrédient a disparu entre le choix et l'ajout
        $this->linkedAccount();
        $this->commandBus->willThrow(AddSpokenItemCommand::class, new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->jsonBody()['response']['outputSpeech']['text']);
        $this->assertStringNotContainsString('INGREDIENT_NOT_FOUND', (string) $this->client->getResponse()->getContent());
    }

    public function testAFailureToResolveTheTokenIsAnsweredWithASentence(): void
    {
        // Given
        $this->queryBus->willThrow(ResolveAccessGrantQuery::class, new RuntimeException('connexion perdue'));

        // When
        $this->post(AlexaTestRequest::launch()->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(200, $this->statusCode());
        $this->assertSame("Je n'ai pas pu faire ça. Réessaie dans un instant.", $this->jsonBody()['response']['outputSpeech']['text']);
    }

    public function testTheVerifierGetsTheRawBodyAndTheTwoHeadersExactlyAsReceived(): void
    {
        // Given : un corps dont les octets ne survivraient pas à un décodage puis un réencodage (espaces, ordre, échappements)
        $this->linkedAccount();
        $body = '{ "version":"1.0",  "request":{"type":"LaunchRequest","locale":"fr-FR","timestamp":"2026-10-25T12:00:00Z"}, "café":"\/" }';

        // When
        $this->postRaw($body, 'https://s3.amazonaws.com/echo.api/echo-api-cert-1.pem', 'U2lnbmF0dXJl');

        // Then
        $this->assertSame([['body' => $body, 'certChainUrl' => 'https://s3.amazonaws.com/echo.api/echo-api-cert-1.pem', 'signature' => 'U2lnbmF0dXJl']], $this->verifier()->verified);
    }

    public function testARequestThatIsNotProvenToComeFromAlexaIsRefusedWith400AndNothingElse(): void
    {
        // Given
        $this->useVerifier(AlexaRequestVerifierDouble::refusing());

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then : pas un mot sur la raison, rien n'est lu ni écrit
        $this->assertSame(400, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'INVALID_ALEXA_REQUEST']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testAVerifierThatCrashesIsARefusalToo(): void
    {
        // Given : dans le doute, on refuse
        $this->useVerifier(AlexaRequestVerifierDouble::crashing());

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(400, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'INVALID_ALEXA_REQUEST']], $this->jsonBody());
        $this->assertStringNotContainsString('panne', (string) $this->client->getResponse()->getContent());
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testABodyThatIsNotJsonIsRefusedEvenIfTheVerifierLetItThrough(): void
    {
        foreach (['', 'pas du json', '[1,2', str_repeat('[', 40) . str_repeat(']', 40)] as $body) {
            // When
            $this->postRaw($body);

            // Then
            $this->assertSame(400, $this->statusCode(), $body);
            $this->assertSame(['error' => ['message' => 'INVALID_ALEXA_REQUEST']], $this->jsonBody());
        }
        $this->assertSame([], $this->commandBus->dispatched());
    }

    public function testABodyLargerThanTheVerifierAcceptsIsReadOnlyUpToTheLimit(): void
    {
        // Given : un octet de plus que la limite du vérificateur
        $body = str_repeat('a', 1048576 + 1);

        // When
        $this->postRaw($body);

        // Then : le vérificateur ne reçoit que la limite plus un octet (il refuse au-delà de la limite), jamais le corps entier
        $this->assertSame(1048576 + 1, strlen($this->verifier()->verified[0]['body']));
        $this->assertSame(400, $this->statusCode());
    }

    public function testOnlyPostIsAllowed(): void
    {
        $this->client->request('GET', self::URL);

        $this->assertSame(405, $this->statusCode());
        $this->assertSame([], $this->verifier()->verified);
    }

    public function testWhenTheSwitchIsOffTheRouteAnswers404BeforeAnythingIsVerified(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));

        // When
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN));

        // Then
        $this->assertSame(404, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $this->verifier()->verified);
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testAnAddressIsLimitedToSixtyRequestsPerMinuteBeforeAnythingIsVerified(): void
    {
        // When : soixante requêtes passent (refusées par le vérificateur : elles comptent quand même), la soixante et unième est arrêtée
        $this->useVerifier(AlexaRequestVerifierDouble::refusing());
        for ($i = 1; $i <= 60; $i++) {
            $this->postRaw('{}', ip: '203.0.113.60');
            $this->assertSame(400, $this->statusCode(), "requête $i");
        }
        $this->postRaw('{}', ip: '203.0.113.60');

        // Then : 429 avant la vérification
        $this->assertSame(429, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_RATE_LIMITED']], $this->jsonBody());
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertCount(60, $this->verifier()->verified);

        // Then : une autre adresse n'est pas touchée
        $this->postRaw('{}', ip: '203.0.113.61');
        $this->assertSame(400, $this->statusCode());
    }

    public function testChangingTheIpv6AddressInsideTheSameSlash64DoesNotEscapeTheLimit(): void
    {
        // Given : le scénario de la revue de sécurité du 2026-10-25, soixante-dix requêtes depuis 2001:db8:2::1 à 2001:db8:2::46
        $this->useVerifier(AlexaRequestVerifierDouble::refusing());

        // When
        $statuses = [];
        for ($i = 1; $i <= 70; $i++) {
            $this->postRaw('{}', ip: sprintf('2001:db8:2::%x', $i));
            $statuses[] = $this->statusCode();
        }

        // Then : soixante refus du vérificateur (400), puis dix 429 avant toute vérification
        $this->assertSame([400 => 60, 429 => 10], array_count_values($statuses));
        $this->assertCount(60, $this->verifier()->verified);

        // Then : une adresse d'un autre /64 n'est pas touchée
        $this->postRaw('{}', ip: '2001:db8:3::1');
        $this->assertSame(400, $this->statusCode());
    }

    public function testALinkIsLimitedToSixtyRequestsPerMinuteAndTheSixtyFirstIsAskedToWait(): void
    {
        // Given : deux liaisons, des adresses différentes à chaque requête (le plafond par adresse est un autre sujet)
        $this->commandBus->willReturn(AddSpokenItemCommand::class, new AddSpokenItemResult("C'est noté : pain.", false, true));
        $this->queryBus->willReturn(ResolveAccessGrantQuery::class, new ResolvedAccessGrantDTO('user-1', 'link-1', 'amzn1.ask.account.ME', new DateTimeImmutable()));

        // When : soixante requêtes passent
        for ($i = 1; $i <= 60; $i++) {
            $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN), ip: long2ip(ip2long('198.51.100.0') + $i));
            $this->assertSame("C'est noté : pain.", $this->jsonBody()['response']['outputSpeech']['text'], "requête $i");
        }
        $before = count($this->commandBus->dispatched());
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN), ip: '198.51.100.200');

        // Then : la suivante reçoit une phrase (200, jamais une erreur), et rien n'est ajouté
        $this->assertSame(200, $this->statusCode());
        $this->assertSame([
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => 'Un instant, réessaie dans une minute.'],
                'shouldEndSession' => true,
            ],
        ], $this->jsonBody());
        $this->assertCount($before, $this->commandBus->dispatched());

        // Then : une autre liaison n'est pas touchée
        $this->queryBus->willReturn(ResolveAccessGrantQuery::class, new ResolvedAccessGrantDTO('user-2', 'link-2'));
        $this->post(AlexaTestRequest::addItem('du pain')->withAccessToken('another-token'), ip: '198.51.100.201');
        $this->assertSame("C'est noté : pain.", $this->jsonBody()['response']['outputSpeech']['text']);
    }

    private function linkedAccount(string $userId = 'user-1', string $linkId = 'link-1', ?string $alexaUserId = AlexaTestRequest::USER_ID, ?DateTimeImmutable $lastUsedAt = new DateTimeImmutable()): void
    {
        $this->queryBus->willReturn(ResolveAccessGrantQuery::class, new ResolvedAccessGrantDTO($userId, $linkId, $alexaUserId, $lastUsedAt));
    }

    /**
     * Le vérificateur de ce test, à choisir AVANT la première requête (le service est figé dès qu'il sert) ; sans choix, il accepte tout.
     */
    private function useVerifier(AlexaRequestVerifierDouble $verifier): void
    {
        $this->verifier = $verifier;
        static::getContainer()->set(AlexaRequestVerifier::class, $verifier);
    }

    private function verifier(): AlexaRequestVerifierDouble
    {
        if ($this->verifier === null) {
            $this->useVerifier(AlexaRequestVerifierDouble::accepting());
        }

        return $this->verifier;
    }

    private function post(AlexaTestRequest $request, string $ip = '127.0.0.1'): void
    {
        $this->postRaw($request->toJson(), ip: $ip);
    }

    private function postRaw(string $body, string $certChainUrl = AlexaTestRequest::CERT_CHAIN_URL, string $signature = 'c2lnbmF0dXJl', string $ip = '127.0.0.1'): void
    {
        $this->verifier();
        $this->client->request('POST', self::URL, content: $body, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGNATURECERTCHAINURL' => $certChainUrl,
            'HTTP_SIGNATURE_256' => $signature,
            'REMOTE_ADDR' => $ip,
        ]);
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }
}
