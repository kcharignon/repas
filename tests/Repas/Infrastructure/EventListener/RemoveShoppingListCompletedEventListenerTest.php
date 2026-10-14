<?php

namespace Repas\Tests\Repas\Infrastructure\EventListener;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Repas\Domain\Event\NewShoppingListCreatedEvent;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\EventListener\RemoveShoppingListCompletedEventListener;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\MockCommandBus;

class RemoveShoppingListCompletedEventListenerTest extends TestCase
{
    public function testRemovesTheCompletedListsOfTheOwnerOnly(): void
    {
        // Given
        $owner = new UserBuilder()->withId('owner-id')->build();
        $other = new UserBuilder()->withId('other-id')->build();
        $shoppingLists = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('completed')->withOwner($owner)->withStatus(ShoppingListStatus::COMPLETED)->build(),
            new ShoppingListBuilder()->withId('paused')->withOwner($owner)->withStatus(ShoppingListStatus::PAUSED)->build(),
            new ShoppingListBuilder()->withId('new')->withOwner($owner)->build(),
            new ShoppingListBuilder()->withId('completed-of-other')->withOwner($other)->withStatus(ShoppingListStatus::COMPLETED)->build(),
        ]);
        $bus = new MockCommandBus();

        // When
        new RemoveShoppingListCompletedEventListener($bus, $shoppingLists)(new NewShoppingListCreatedEvent('new', 'owner-id'));

        // Then
        $this->assertEquals([new RemoveShoppingListCommand('completed')], $bus->dispatched());
    }

    public function testDoesNothingWhenNoListIsCompleted(): void
    {
        // Given
        $owner = new UserBuilder()->withId('owner-id')->build();
        $shoppingLists = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->withId('new')->withOwner($owner)->build(),
        ]);
        $bus = new MockCommandBus();

        // When
        new RemoveShoppingListCompletedEventListener($bus, $shoppingLists)(new NewShoppingListCreatedEvent('new', 'owner-id'));

        // Then
        $this->assertSame([], $bus->dispatched());
    }
}
