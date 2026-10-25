<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryRepository\UserVoiceDataInMemoryRepository;
use Repas\Voice\Application\Command\PurgeUserVoiceData\PurgeUserVoiceDataCommand;
use Repas\Voice\Application\Command\PurgeUserVoiceData\PurgeUserVoiceDataHandler;

class PurgeUserVoiceDataHandlerTest extends TestCase
{
    public function testTheVoiceDataOfTheDeletedUserAreErasedAndOnlyHis(): void
    {
        // Given
        $voiceData = new UserVoiceDataInMemoryRepository();

        // When
        new PurgeUserVoiceDataHandler($voiceData)(new PurgeUserVoiceDataCommand('departing'));

        // Then
        $this->assertSame(['departing'], $voiceData->erasedUsers);
    }
}
