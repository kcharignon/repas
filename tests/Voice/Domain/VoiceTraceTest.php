<?php

namespace Repas\Tests\Voice\Domain;


use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Repas\Voice\Domain\Model\TraceOutcome;
use Repas\Voice\Domain\Model\TracedFailure;
use Repas\Voice\Domain\Model\TracedRequest;
use Repas\Voice\Domain\Model\VoiceTrace;

/**
 * Un échange gardé dans la trace (ADR 0029 décision 14) : ses bornes, ses issues et ce que la trace retient d'une panne.
 */
class VoiceTraceTest extends TestCase
{
    public function testAnExchangeCarriesWhatTheRequestAndTheAnswerSaid(): void
    {
        // Given
        $at = new DateTimeImmutable('2026-11-15 10:00:00');
        $request = new TracedRequest('IntentRequest', 'AddItemIntent', false, ['request' => ['type' => 'IntentRequest']]);

        // When
        $trace = VoiceTrace::record($at, 'user-1', 'link-1', $request, ['response' => []], TraceOutcome::ANSWERED, 'AddSpokenItem', 42, null);

        // Then
        $this->assertSame([$at, 'user-1', 'link-1', 'IntentRequest', 'AddItemIntent', false, TraceOutcome::ANSWERED, 'AddSpokenItem', 42], [
            $trace->createdAt, $trace->userId, $trace->linkId, $trace->requestType, $trace->intent, $trace->sessionNew, $trace->outcome, $trace->decision, $trace->durationMs,
        ]);
        $this->assertSame(['request' => ['type' => 'IntentRequest']], $trace->request);
        $this->assertSame(['response' => []], $trace->response);
        $this->assertNull($trace->failure);
    }

    public function testTheShortColumnsAreCutToTheirSizeAndAnUnknownTypeIsNamed(): void
    {
        // Given : un type, une intention et une décision plus longs que leurs colonnes
        $request = new TracedRequest(str_repeat('t', 80), str_repeat('i', 140), null, []);

        // When
        $trace = VoiceTrace::record(new DateTimeImmutable(), null, null, $request, [], TraceOutcome::ACCOUNT_NOT_LINKED, str_repeat('d', 90), -5, null);
        $unknown = VoiceTrace::record(new DateTimeImmutable(), null, null, new TracedRequest(null, null, null, []), [], TraceOutcome::SESSION_ENDED, null, 0, null);

        // Then : une durée négative n'existe pas
        $this->assertSame([50, 100, 60, 0], [strlen($trace->requestType), strlen((string) $trace->intent), strlen((string) $trace->decision), $trace->durationMs]);
        $this->assertSame('inconnu', $unknown->requestType);
    }

    public function testEveryOutcomeHasAWordForTheConsole(): void
    {
        foreach (TraceOutcome::cases() as $outcome) {
            $this->assertNotSame('', $outcome->label(), $outcome->value);
        }
        $this->assertSame('PANNE', TraceOutcome::FAILED->label());
        $this->assertSame('compte non lié', TraceOutcome::ACCOUNT_NOT_LINKED->label());
    }

    public function testAFailureKeepsTheClassThePlaceAndTheTruncatedMessage(): void
    {
        // Given : une exception dont le message dépasse 500 caractères
        $exception = new RuntimeException('échec ' . str_repeat('é', 700));

        // When
        $failure = TracedFailure::fromThrowable($exception);

        // Then : pas de dossier dans l'endroit, un message de 500 caractères, pas de cause
        $this->assertSame(RuntimeException::class, $failure->class);
        $this->assertMatchesRegularExpression('/^VoiceTraceTest\.php:\d+$/', $failure->location);
        $this->assertSame(500, mb_strlen($failure->message));
        $this->assertNull($failure->previousClass);
        $this->assertSame(['class', 'location', 'message'], array_keys($failure->toArray()));
    }

    public function testAFailureWrappedByTheBusKeepsTheCauseToo(): void
    {
        // Given : l'enveloppe du bus et l'exception d'origine
        $exception = new Exception('enveloppe', 0, new RuntimeException('cause'));

        // When
        $data = TracedFailure::fromThrowable($exception)->toArray();

        // Then
        $this->assertSame(['class', 'location', 'message', 'previousClass', 'previousLocation', 'previousMessage'], array_keys($data));
        $this->assertSame([Exception::class, 'enveloppe', RuntimeException::class, 'cause'], [$data['class'], $data['message'], $data['previousClass'], $data['previousMessage']]);
        $this->assertMatchesRegularExpression('/^VoiceTraceTest\.php:\d+$/', $data['previousLocation']);
    }

    public function testATextWithANulCharacterOrABrokenByteIsMadeSafeForAJsonColumn(): void
    {
        $this->assertSame('ab', VoiceTrace::bounded("a\0b"));
        $this->assertSame(1, preg_match('//u', VoiceTrace::bounded("a\xFFb")), 'UTF-8 valide');
        $this->assertSame('abc', VoiceTrace::bounded('abcdef', 3));
    }
}
