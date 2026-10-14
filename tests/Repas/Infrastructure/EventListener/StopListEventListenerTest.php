<?php

namespace Repas\Tests\Repas\Infrastructure\EventListener;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\StoppedShoppingList\StoppedShoppingListCommand;
use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Infrastructure\EventListener\StopListEventListener;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\MockCommandBus;

class StopListEventListenerTest extends TestCase
{
    public function testStopsTheListWhenItsLastLineIsTicked(): void
    {
        // Given
        $rows = new ShoppingListRowInMemoryRepository([
            new ShoppingListRowBuilder()->withShoppingListId('list-id')->checked()->build(),
            new ShoppingListRowBuilder()->withShoppingListId('list-id')->checked()->build(),
            new ShoppingListRowBuilder()->withShoppingListId('another-list')->build(),
        ]);
        $bus = new MockCommandBus();

        // When
        new StopListEventListener($rows, $bus)(new LineTickedEvent('list-id'));

        // Then
        $this->assertEquals([new StoppedShoppingListCommand('list-id')], $bus->dispatched());
    }

    public function testKeepsTheListActiveWhileALineRemainsToTick(): void
    {
        // Given
        $rows = new ShoppingListRowInMemoryRepository([
            new ShoppingListRowBuilder()->withShoppingListId('list-id')->checked()->build(),
            new ShoppingListRowBuilder()->withShoppingListId('list-id')->build(),
        ]);
        $bus = new MockCommandBus();

        // When
        new StopListEventListener($rows, $bus)(new LineTickedEvent('list-id'));

        // Then
        $this->assertSame([], $bus->dispatched());
    }
}
