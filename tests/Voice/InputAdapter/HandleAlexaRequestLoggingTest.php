<?php

namespace Repas\Tests\Voice\InputAdapter;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\AlexaRequestVerifierDouble;
use Repas\Tests\Helper\ContextSpyLogger;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\MockQueryBus;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueCommand;
use Repas\Voice\Application\Command\RecordVoiceLinkUse\RecordVoiceLinkUseCommand;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolveAccessGrantQuery;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Service\AlexaDialogue;
use Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest\HandleAlexaRequestApiController;
use Repas\Voice\Infrastructure\Http\VoiceLinkRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Le webhook d'Alexa ne journalise JAMAIS le corps de la requête (VOX-203, VOX-204) : il contient la voix transcrite. Ni la phrase, ni le
 * jeton, ni le message d'une exception qui pourrait les citer n'arrivent au journal ; la classe de l'exception et son endroit suffisent à
 * retrouver une panne. Le controller est construit à la main avec un journal qui garde le contexte.
 */
class HandleAlexaRequestLoggingTest extends TestCase
{
    private const string SECRET_PHRASE = 'PHRASE-SECRETE-A-NE-JAMAIS-JOURNALISER';
    private const string SECRET_TOKEN = 'JETON-SECRET-A-NE-JAMAIS-JOURNALISER';

    private ContextSpyLogger $logger;
    private MockQueryBus $queryBus;
    private MockCommandBus $commandBus;

    protected function setUp(): void
    {
        $this->logger = new ContextSpyLogger();
        $this->queryBus = new MockQueryBus();
        $this->commandBus = new MockCommandBus();
        $this->queryBus->willReturn(ResolveAccessGrantQuery::class, new ResolvedAccessGrantDTO('user-1', 'link-1', null, new DateTimeImmutable()));
    }

    public function testAFailureOfTheAddIsLoggedByClassAndPlaceNeverByMessage(): void
    {
        // Given : le message de l'exception cite la phrase dite et le jeton
        $this->commandBus->willThrow(AddSpokenItemCommand::class, new RuntimeException('échec pour ' . self::SECRET_PHRASE . ' avec ' . self::SECRET_TOKEN));

        // When
        $response = $this->controller()($this->request(AlexaTestRequest::addItem(self::SECRET_PHRASE)->withAccessToken(self::SECRET_TOKEN)->toJson()));

        // Then
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('error', $this->logger->records[0]['level']);
        $this->assertSame(RuntimeException::class, $this->logger->records[0]['context']['exception']);
        $this->assertMatchesRegularExpression('/^HandleAlexaRequestLoggingTest\.php:\d+$/', $this->logger->records[0]['context']['location']);
        $this->assertNothingSecretWasLoggedNorAnswered($response->getContent());
    }

    public function testAFailureOfTheDialogueIsLoggedByClassAndPlaceNeverTheStateTheSentenceOrTheMessage(): void
    {
        // Given : l'état de la séance porte la phrase entendue et un nom ; le message de l'exception les cite aussi
        $state = SpokenDialogueState::choose(self::SECRET_PHRASE, [new DialogueOption('persil-u', 'persil')], 'secret-name');
        $this->commandBus->willThrow(AnswerSpokenDialogueCommand::class, new RuntimeException('échec pour ' . self::SECRET_PHRASE . ' avec ' . self::SECRET_TOKEN));

        // When
        $response = $this->controller()($this->request(AlexaTestRequest::yes()->withAccessToken(self::SECRET_TOKEN)->withSessionAttributes(['dialogue' => $state->toArray()])->toJson()));

        // Then : un seul enregistrement, de la classe et de l'endroit seulement
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->logger->records);
        $this->assertSame(['exception', 'location'], array_keys($this->logger->records[0]['context']));
        $this->assertStringNotContainsString('secret-name', $this->logger->everything());
        $this->assertStringNotContainsString('persil-u', $this->logger->everything());
        $this->assertStringNotContainsString('secret-name', (string) $response->getContent());
        $this->assertNothingSecretWasLoggedNorAnswered($response->getContent());
    }

    public function testTheDialogueIsNeverLoggedWhenItGoesWell(): void
    {
        // Given
        $state = SpokenDialogueState::choose(self::SECRET_PHRASE, [], 'secret-name');
        $this->commandBus->willReturn(AnswerSpokenDialogueCommand::class, new \Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult('Dans quel rayon ?', true, false, $state->askingDepartment(), 'Dans quel rayon ?'));

        // When
        $response = $this->controller()($this->request(AlexaTestRequest::yes()->withAccessToken(self::SECRET_TOKEN)->withSessionAttributes(['dialogue' => $state->toArray()])->toJson()));

        // Then : la phrase revient dans l'état de la séance (c'est son rôle), jamais au journal
        $this->assertSame([], $this->logger->records);
    }

    public function testAFailureOfTheTrackingIsLoggedByClassOnly(): void
    {
        // Given
        $this->commandBus->willThrow(RecordVoiceLinkUseCommand::class, new RuntimeException('suivi impossible pour ' . self::SECRET_TOKEN));

        // When
        $response = $this->controller()($this->request(AlexaTestRequest::launch()->withAccessToken(self::SECRET_TOKEN)->toJson()));

        // Then
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['level' => 'warning', 'message' => 'Suivi de la liaison vocale impossible.', 'context' => ['exception' => RuntimeException::class]]], $this->logger->records);
        $this->assertNothingSecretWasLoggedNorAnswered($response->getContent());
    }

    public function testARefusedRequestLogsNothingItself(): void
    {
        // When : le vérificateur journalise le refus (sa catégorie), le controller n'ajoute rien
        $controller = $this->controller(AlexaRequestVerifierDouble::refusing());

        try {
            $controller($this->request(AlexaTestRequest::addItem(self::SECRET_PHRASE)->withAccessToken(self::SECRET_TOKEN)->toJson()));
            $this->fail('La requête aurait dû être refusée.');
        } catch (VoiceException $exception) {
            // Then
            $this->assertSame(VoiceException::INVALID_REQUEST, $exception->getMessage());
        }
        $this->assertSame([], $this->logger->records);
    }

    public function testAnUnexpectedFailureOfTheVerifierIsALoggedClassAndARefusal(): void
    {
        // When
        $controller = $this->controller(AlexaRequestVerifierDouble::crashing());

        try {
            $controller($this->request(AlexaTestRequest::addItem(self::SECRET_PHRASE)->withAccessToken(self::SECRET_TOKEN)->toJson()));
            $this->fail('La requête aurait dû être refusée.');
        } catch (VoiceException) {
            // Then
        }
        $this->assertSame([['level' => 'warning', 'message' => "Requête d'Alexa illisible.", 'context' => ['exception' => RuntimeException::class]]], $this->logger->records);
        $this->assertNothingSecretWasLoggedNorAnswered('');
    }

    public function testABodyThatIsNotJsonIsRefusedAndNeverLogged(): void
    {
        // When
        $controller = $this->controller();

        try {
            $controller($this->request('pas du json : ' . self::SECRET_PHRASE));
            $this->fail('La requête aurait dû être refusée.');
        } catch (VoiceException) {
            // Then
        }
        $this->assertNothingSecretWasLoggedNorAnswered('');
    }

    private function assertNothingSecretWasLoggedNorAnswered(string|false $answer): void
    {
        $this->assertStringNotContainsString(self::SECRET_PHRASE, $this->logger->everything());
        $this->assertStringNotContainsString(self::SECRET_TOKEN, $this->logger->everything());
        $this->assertStringNotContainsString(self::SECRET_PHRASE, (string) $answer);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, (string) $answer);
    }

    private function controller(?AlexaRequestVerifierDouble $verifier = null): HandleAlexaRequestApiController
    {
        return new HandleAlexaRequestApiController(
            $verifier ?? AlexaRequestVerifierDouble::accepting(),
            new AlexaDialogue(),
            $this->queryBus,
            $this->commandBus,
            new VoiceLinkRateLimiter(new RateLimiterFactory(['id' => 'test', 'policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 minute'], new InMemoryStorage())),
            $this->logger,
        );
    }

    private function request(string $body): Request
    {
        return Request::create('/api/voice/alexa', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGNATURECERTCHAINURL' => AlexaTestRequest::CERT_CHAIN_URL,
            'HTTP_SIGNATURE_256' => 'c2lnbmF0dXJl',
        ], content: $body);
    }
}
