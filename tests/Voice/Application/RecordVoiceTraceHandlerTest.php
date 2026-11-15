<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\ControlledVoiceTraceFeature;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\VoiceTraceInMemoryRepository;
use Repas\Voice\Application\Command\RecordVoiceTrace\RecordVoiceTraceCommand;
use Repas\Voice\Application\Command\RecordVoiceTrace\RecordVoiceTraceHandler;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\TracedFailure;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Domain\Service\AlexaTraceSanitizer;

/**
 * Garder un échange avec Alexa dans la trace (ADR 0029 décision 14) : la requête assainie, la réponse, l'issue, la durée, la panne ; les 1 000 plus
 * récents seulement ; rien du tout interrupteur coupé.
 */
class RecordVoiceTraceHandlerTest extends TestCase
{
    private const string TOKEN = 'FACTICE-JETON-A-NE-JAMAIS-GARDER';

    private VoiceTraceInMemoryRepository $traces;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->traces = new VoiceTraceInMemoryRepository();
        $this->now = new DateTimeImmutable('2026-11-15 12:00:00');
    }

    public function testTheExchangeIsKeptWithTheSanitizedRequestTheAnswerTheOutcomeAndTheDuration(): void
    {
        // Given
        $body = json_decode(AlexaTestRequest::answerDepartment('divers', null)->withAccessToken(self::TOKEN)->toJson(), true);
        $command = new RecordVoiceTraceCommand('user-1', 'link-1', $body, AlexaResponse::say('Je ne comprends pas.'), TraceOutcome::ANSWERED, 'AnswerSpokenDialogue:department', 37);

        // When
        $this->handler()($command);

        // Then
        $trace = $this->traces->last();
        $this->assertInstanceOf(VoiceTrace::class, $trace);
        $this->assertSame([$this->now, 'user-1', 'link-1', 'IntentRequest', 'AnswerDepartmentIntent', true, TraceOutcome::ANSWERED, 'AnswerSpokenDialogue:department', 37], [
            $trace->createdAt, $trace->userId, $trace->linkId, $trace->requestType, $trace->intent, $trace->sessionNew, $trace->outcome, $trace->decision, $trace->durationMs,
        ]);
        $this->assertSame('divers', $trace->request['request']['intent']['slots']['department']['value']);
        $this->assertSame('Je ne comprends pas.', $trace->response['response']['outputSpeech']['text']);
        $this->assertNull($trace->failure);
    }

    public function testNothingOfTheTokenIsWritten(): void
    {
        // Given : le corps porte le jeton d'accès (session et contexte)
        $body = json_decode(AlexaTestRequest::addItem('du pain')->withAccessToken(self::TOKEN)->toJson(), true);

        // When
        $this->handler()(new RecordVoiceTraceCommand('user-1', 'link-1', $body, AlexaResponse::say('Noté.'), TraceOutcome::ANSWERED, 'AddSpokenItem', 5));

        // Then
        $trace = $this->traces->last();
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode([$trace->request, $trace->response, $trace->failure?->toArray()]));
        $this->assertStringNotContainsString('TESTUSER', (string) json_encode($trace->request), "l'identifiant d'utilisateur Alexa non plus");
    }

    public function testAFailureIsKeptWithItsClassItsPlaceAndItsTruncatedMessage(): void
    {
        // Given
        $failure = TracedFailure::fromThrowable(new RuntimeException('échec pour du pain'));
        $command = new RecordVoiceTraceCommand('user-1', 'link-1', json_decode(AlexaTestRequest::addItem('du pain')->toJson(), true), AlexaResponse::say('Je n\'ai pas pu faire ça.'), TraceOutcome::FAILED, 'AddSpokenItem', 12, $failure);

        // When
        $this->handler()($command);

        // Then
        $trace = $this->traces->last();
        $this->assertSame(TraceOutcome::FAILED, $trace->outcome);
        $this->assertSame($failure, $trace->failure);
        $this->assertSame(RuntimeException::class, $trace->failure->class);
    }

    /**
     * @return iterable<string, array{TraceOutcome}>
     */
    public static function outcomes(): iterable
    {
        foreach (TraceOutcome::cases() as $outcome) {
            yield $outcome->value => [$outcome];
        }
    }

    #[DataProvider('outcomes')]
    public function testEveryOutcomeIsKeptWithoutAUserWhenNoTokenWasResolved(TraceOutcome $outcome): void
    {
        // When : un échange sans compte (jeton non résolu, séance terminée, langue étrangère)
        $this->handler()(new RecordVoiceTraceCommand(null, null, ['request' => ['type' => 'LaunchRequest']], AlexaResponse::silence(), $outcome, null, 1));

        // Then
        $trace = $this->traces->last();
        $this->assertSame([null, null, 'LaunchRequest', $outcome], [$trace->userId, $trace->linkId, $trace->requestType, $trace->outcome]);
    }

    public function testTheSwitchOffMeansNothingIsWritten(): void
    {
        // When
        $this->handler(new ControlledVoiceTraceFeature(false))(new RecordVoiceTraceCommand('user-1', 'link-1', ['request' => ['type' => 'LaunchRequest']], AlexaResponse::say('Salut.'), TraceOutcome::ANSWERED, null, 1));

        // Then
        $this->assertSame([], $this->traces->all());
    }

    public function testTheThousandAndFirstExchangeRemovesTheOldestOne(): void
    {
        // Given : 1 000 échanges gardés (la durée sert de repère : 1 pour le plus ancien)
        $handler = $this->handler();
        for ($number = 1; $number <= VoiceTrace::RETENTION; $number++) {
            $handler($this->command($number));
        }
        $this->assertCount(1000, $this->traces->all());
        $this->assertSame(1, array_values($this->traces->all())[0]->durationMs);

        // When
        $handler($this->command(1001));

        // Then : toujours 1 000, le plus ancien est parti, le nouveau est le plus récent
        $kept = array_values($this->traces->all());
        $this->assertCount(1000, $kept);
        $this->assertSame([2, 1001], [$kept[0]->durationMs, $kept[999]->durationMs]);
    }

    private function command(int $durationMs): RecordVoiceTraceCommand
    {
        return new RecordVoiceTraceCommand('user-1', 'link-1', ['request' => ['type' => 'LaunchRequest']], AlexaResponse::say('Salut.'), TraceOutcome::ANSWERED, null, $durationMs);
    }

    private function handler(?ControlledVoiceTraceFeature $feature = null): RecordVoiceTraceHandler
    {
        return new RecordVoiceTraceHandler($this->traces, $feature ?? new ControlledVoiceTraceFeature(true), new AlexaTraceSanitizer(), new FrozenClock($this->now));
    }
}
