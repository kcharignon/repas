<?php

namespace Repas\Tests\Repas\Infrastructure\EventListener;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\PurgeUserData\PurgeUserDataCommand;
use Repas\Repas\Infrastructure\EventListener\PurgeUserDataOnUserDeletedListener;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Tests\Helper\MockCommandBus;

class PurgeUserDataOnUserDeletedListenerTest extends TestCase
{
    public function testADeletedUserTriggersThePurgeOfHisData(): void
    {
        // Given
        $bus = new MockCommandBus();

        // When
        new PurgeUserDataOnUserDeletedListener($bus)(new UserDeletedEvent('user-id'));

        // Then
        $this->assertEquals([new PurgeUserDataCommand('user-id')], $bus->dispatched());
    }
}
