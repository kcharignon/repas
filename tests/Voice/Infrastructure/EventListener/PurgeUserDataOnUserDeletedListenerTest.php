<?php

namespace Repas\Tests\Voice\Infrastructure\EventListener;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Voice\Application\Command\PurgeUserVoiceData\PurgeUserVoiceDataCommand;
use Repas\Voice\Infrastructure\EventListener\PurgeUserDataOnUserDeletedListener;

class PurgeUserDataOnUserDeletedListenerTest extends TestCase
{
    public function testADeletedUserTriggersThePurgeOfHisVoiceData(): void
    {
        // Given
        $bus = new MockCommandBus();

        // When
        new PurgeUserDataOnUserDeletedListener($bus)(new UserDeletedEvent('user-id'));

        // Then
        $this->assertEquals([new PurgeUserVoiceDataCommand('user-id')], $bus->dispatched());
    }
}
