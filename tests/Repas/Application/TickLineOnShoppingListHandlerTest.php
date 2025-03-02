<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Application\TickLineOnShoppingList\TickLineOnShoppingListHandler;
use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\RepasAssert;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class TickLineOnShoppingListHandlerTest extends TestCase
{
    private readonly TickLineOnShoppingListHandler $handler;
    private readonly ShoppingListRowRepository $shoppingListRowRepository;
    private readonly EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->shoppingListRowRepository = new ShoppingListRowInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();

        $this->handler = new TickLineOnShoppingListHandler(
            $this->shoppingListRowRepository,
            $this->eventDispatcher,
        );

        $shoppingListRow = new ShoppingListRowBuilder()
            ->withId('shopping-list-row-id')
            ->withShoppingListId('shopping-list-id')
            ->build();
        $this->shoppingListRowRepository->save($shoppingListRow);
    }

    public function testTickLineOnShoppingSuccess(): void
    {
        // Arrange
        $command = new TickLineOnShoppingListCommand('shopping-list-row-id');

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ShoppingListRowBuilder()
            ->withId('shopping-list-row-id')
            ->withShoppingListId('shopping-list-id')
            ->checked()
            ->build();
        $actual = $this->shoppingListRowRepository->findOneById('shopping-list-row-id');
        RepasAssert::assertShoppingListRow($expected, $actual);

        $actualEvent = $this->eventDispatcher->getLastEventDispatched();
        $expectedEvent = new LineTickedEvent('shopping-list-id');
        $this->assertEquals($expectedEvent, $actualEvent);
    }

    public function testHandleFailedTickLineOnShoppingUnknownRow(): void
    {
        // Arrange
        $command = new TickLineOnShoppingListCommand('shopping-list-row-id-not-found');

        // Assert
        $this->expectExceptionObject(ShoppingListRowException::notFound('shopping-list-row-id-not-found'));

        // Act
        ($this->handler)($command);
    }
}
