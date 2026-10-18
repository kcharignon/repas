<?php

namespace Repas\Tests\Drive\Infrastructure\EventListener;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataCommand;
use Repas\Drive\Infrastructure\EventListener\PurgeUserDataOnUserDeletedListener;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Tests\Helper\MockCommandBus;

class PurgeUserDataOnUserDeletedListenerTest extends TestCase
{
    public function testADeletedUserTriggersThePurgeOfHisDriveData(): void
    {
        // Given
        $bus = new MockCommandBus();

        // When
        new PurgeUserDataOnUserDeletedListener($bus)(new UserDeletedEvent('user-id'));

        // Then
        $this->assertEquals([new PurgeUserDriveDataCommand('user-id')], $bus->dispatched());
    }
}
