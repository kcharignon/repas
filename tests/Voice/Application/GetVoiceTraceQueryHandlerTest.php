<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryLoader\VoiceTraceInMemoryLoader;
use Repas\Voice\Application\Query\GetVoiceTrace\GetVoiceTraceQuery;
use Repas\Voice\Application\Query\GetVoiceTrace\GetVoiceTraceQueryHandler;
use Repas\Voice\Application\Query\GetVoiceTrace\VoiceTraceDetailDTO;
use Repas\Voice\Application\Query\ListVoiceTraces\VoiceTraceSummaryDTO;

class GetVoiceTraceQueryHandlerTest extends TestCase
{
    public function testTheNumberIsPassedToTheLoaderAndTheExchangeIsReturnedAsIs(): void
    {
        // Given
        $summary = new VoiceTraceSummaryDTO(7, new DateTimeImmutable('2026-11-15 12:00:00'), 'IntentRequest', 'AddItemIntent', true, 'ANSWERED', 'AddSpokenItem', 12, 'Noté.', null, null, null);
        $detail = new VoiceTraceDetailDTO($summary, 'user-1', 'link-1', ['request' => []], ['response' => []], null);
        $loader = new VoiceTraceInMemoryLoader(detail: $detail);

        // When
        $result = new GetVoiceTraceQueryHandler($loader)(new GetVoiceTraceQuery(7));

        // Then
        $this->assertSame($detail, $result);
        $this->assertSame([7], $loader->askedIds);
    }

    public function testAnUnknownNumberGivesNothing(): void
    {
        // When
        $result = new GetVoiceTraceQueryHandler(new VoiceTraceInMemoryLoader())(new GetVoiceTraceQuery(99));

        // Then
        $this->assertNull($result);
    }
}
