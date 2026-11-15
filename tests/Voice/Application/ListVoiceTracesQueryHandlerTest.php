<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryLoader\VoiceTraceInMemoryLoader;
use Repas\Voice\Application\Query\ListVoiceTraces\ListVoiceTracesQuery;
use Repas\Voice\Application\Query\ListVoiceTraces\ListVoiceTracesQueryHandler;
use Repas\Voice\Application\Query\ListVoiceTraces\VoiceTraceSummaryDTO;

class ListVoiceTracesQueryHandlerTest extends TestCase
{
    public function testTheFiltersArePassedToTheLoaderAndItsAnswerIsReturnedAsIs(): void
    {
        // Given
        $traces = [new VoiceTraceSummaryDTO(7, new DateTimeImmutable('2026-11-15 12:00:00'), 'IntentRequest', 'AddItemIntent', true, 'FAILED', 'AddSpokenItem', 12, 'Je n\'ai pas pu faire ça.', 'item="du pain"', null, 'RuntimeException')];
        $loader = new VoiceTraceInMemoryLoader($traces);
        $since = new DateTimeImmutable('2026-11-15 10:00:00');

        // When
        $result = new ListVoiceTracesQueryHandler($loader)(new ListVoiceTracesQuery(50, 'AddItemIntent', true, $since));

        // Then
        $this->assertSame($traces, $result);
        $this->assertSame([['limit' => 50, 'intent' => 'AddItemIntent', 'failedOnly' => true, 'since' => $since]], $loader->askedLists);
    }

    public function testWithoutFiltersTheTwentyLatestAreAsked(): void
    {
        // Given
        $loader = new VoiceTraceInMemoryLoader();

        // When
        $result = new ListVoiceTracesQueryHandler($loader)(new ListVoiceTracesQuery());

        // Then
        $this->assertSame([], $result);
        $this->assertSame([['limit' => 20, 'intent' => null, 'failedOnly' => false, 'since' => null]], $loader->askedLists);
    }
}
