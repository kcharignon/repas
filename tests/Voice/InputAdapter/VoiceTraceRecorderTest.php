<?php

namespace Repas\Tests\Voice\InputAdapter;


use PHPUnit\Framework\TestCase;
use RuntimeException;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\ContextSpyLogger;
use Repas\Tests\Helper\ControlledVoiceTraceFeature;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Voice\Application\Command\RecordVoiceTrace\RecordVoiceTraceCommand;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest\ExchangeNotes;
use Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest\VoiceTraceRecorder;

/**
 * Ce que le webhook confie à la trace (ADR 0029 décision 14) : rien interrupteur coupé, la commande de trace sinon, et jamais un échec qui remonte.
 */
class VoiceTraceRecorderTest extends TestCase
{
    private const string SECRET = 'FACTICE-MESSAGE-SECRET-A-NE-JAMAIS-JOURNALISER';

    private MockCommandBus $commandBus;
    private ContextSpyLogger $logger;

    protected function setUp(): void
    {
        $this->commandBus = new MockCommandBus();
        $this->logger = new ContextSpyLogger();
    }

    public function testTheSwitchOffSendsNothingToTheBus(): void
    {
        // When
        $this->recorder(false)->record([], AlexaResponse::say('Salut.'), new ExchangeNotes(), 3, null);

        // Then : aucune commande, donc aucune transaction ni requête SQL
        $this->assertSame([], $this->commandBus->dispatched());
        $this->assertSame([], $this->logger->records);
    }

    public function testTheSwitchOnSendsTheExchangeWithWhatTheWebhookLearned(): void
    {
        // Given
        $body = json_decode(AlexaTestRequest::addItem('du pain')->toJson(), true);
        $response = AlexaResponse::say('Noté.');
        $notes = new ExchangeNotes();
        $notes->userId = 'user-1';
        $notes->linkId = 'link-1';
        $notes->decision = 'AddSpokenItem';
        $notes->outcome = TraceOutcome::LINK_RATE_LIMITED;

        // When
        $this->recorder(true)->record($body, $response, $notes, 42, null);

        // Then
        $this->assertEquals([new RecordVoiceTraceCommand('user-1', 'link-1', $body, $response, TraceOutcome::LINK_RATE_LIMITED, 'AddSpokenItem', 42, null)], $this->commandBus->dispatched());
    }

    public function testAFailureIsSentWithItsClassItsPlaceAndItsMessage(): void
    {
        // When
        $this->recorder(true)->record([], AlexaResponse::say('Échec.'), new ExchangeNotes(), 1, new RuntimeException('échec de ' . self::SECRET));

        // Then : la trace garde le message (c'est son but), la commande ne porte pas l'exception elle-même
        $command = $this->commandBus->dispatched()[0];
        $this->assertInstanceOf(RecordVoiceTraceCommand::class, $command);
        $this->assertSame(RuntimeException::class, $command->failure->class);
        $this->assertStringContainsString(self::SECRET, $command->failure->message);
    }

    public function testAFailureOfTheTraceIsSwallowedAndLoggedByItsClassOnly(): void
    {
        // Given : l'écriture de la trace échoue, et le message de l'exception cite le secret
        $this->commandBus->willThrow(RecordVoiceTraceCommand::class, new RuntimeException('base en panne : ' . self::SECRET));

        // When : rien ne remonte
        $this->recorder(true)->record(['request' => ['type' => 'LaunchRequest']], AlexaResponse::say('Salut.'), new ExchangeNotes(), 1, null);

        // Then : un avertissement, la classe seulement, jamais le message
        $this->assertSame([['level' => 'warning', 'message' => "Trace de l'échange avec Alexa impossible.", 'context' => ['exception' => RuntimeException::class]]], $this->logger->records);
        $this->assertStringNotContainsString(self::SECRET, $this->logger->everything());
    }

    private function recorder(bool $enabled): VoiceTraceRecorder
    {
        return new VoiceTraceRecorder(new ControlledVoiceTraceFeature($enabled), $this->commandBus, $this->logger);
    }
}
