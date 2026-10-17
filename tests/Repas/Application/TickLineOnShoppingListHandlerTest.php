<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListHandler;
use Repas\Repas\Domain\Event\LineTickedEvent;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\RepasAssert;

class TickLineOnShoppingListHandlerTest extends TestCase
{
    private readonly TickLineOnShoppingListHandler $handler;
    private readonly ShoppingListRowRepository $shoppingListRowRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->shoppingListRowRepository = new ShoppingListRowInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();

        $this->handler = new TickLineOnShoppingListHandler(
            $this->shoppingListRowRepository,
            $this->shoppingListRepository,
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

    public function testRefusesALineOfASentListAndTicksNothing(): void
    {
        // Arrange : une liste envoyée au drive n'est plus modifiable (ADR 0023)
        $this->shoppingListRepository->save(
            new ShoppingListBuilder()->withId('shopping-list-id')->withStatus(ShoppingListStatus::SENT)->build()
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::sent('shopping-list-id'));

        // Act
        try {
            ($this->handler)(new TickLineOnShoppingListCommand('shopping-list-row-id'));
        } finally {
            $this->assertFalse($this->shoppingListRowRepository->findOneById('shopping-list-row-id')->isChecked());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }
}
