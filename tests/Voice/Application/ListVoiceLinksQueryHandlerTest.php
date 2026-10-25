<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryLoader\VoiceLinkInMemoryLoader;
use Repas\Voice\Application\Query\ListVoiceLinks\ListVoiceLinksQuery;
use Repas\Voice\Application\Query\ListVoiceLinks\ListVoiceLinksQueryHandler;
use Repas\Voice\Application\Query\ListVoiceLinks\VoiceLinkDTO;

class ListVoiceLinksQueryHandlerTest extends TestCase
{
    public function testTheUserIsPassedToTheLoaderAndItsAnswerIsReturnedAsIs(): void
    {
        // Given
        $links = [new VoiceLinkDTO('link-1', 'alexa', '2026-10-25T12:00:00+01:00', null)];
        $loader = new VoiceLinkInMemoryLoader($links);

        // When
        $result = new ListVoiceLinksQueryHandler($loader)(new ListVoiceLinksQuery('user-1'));

        // Then
        $this->assertSame($links, $result);
        $this->assertSame(['user-1'], $loader->askedUserIds);
    }
}
