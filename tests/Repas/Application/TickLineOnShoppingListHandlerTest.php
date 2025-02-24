<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Application\TickLineOnShoppingList\TickLineOnShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\ShoppingListRowBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListRowInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class TickLineOnShoppingListHandlerTest extends TestCase
{
    private readonly TickLineOnShoppingListHandler $handler;
    private readonly ShoppingListRowRepository $shoppingListRowRepository;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {
        $this->shoppingListRowRepository = new ShoppingListRowInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();

        $this->handler = new TickLineOnShoppingListHandler(
            $this->shoppingListRowRepository,
            $this->shoppingListRepository,
        );

        $shoppingListRow = new ShoppingListRowBuilder()
            ->withId('shopping-list-row-id')
            ->withShoppingListId('shopping-list-id')
            ->build();
        $this->shoppingListRowRepository->save($shoppingListRow);
    }

    public function testHandleSuccessfullyTickLineOnShoppingListNotLastRow(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->addIngredient(new IngredientBuilder()->isPasta())
            ->build();
        $this->shoppingListRepository->save($shoppingList);
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
        $shoppingList = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertEquals(ShoppingListStatus::ACTIVE, $shoppingList->getStatus());
    }


    public function testHandleSuccessfullyTickLineOnShoppingListLastRow(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->build();
        $this->shoppingListRepository->save($shoppingList);
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
        $shoppingList = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertEquals(ShoppingListStatus::COMPLETED, $shoppingList->getStatus());
    }

    public function testHandleFailedTickLineOnShoppingUnknownRow(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new TickLineOnShoppingListCommand('shopping-list-row-id-not-found');

        // Assert
        $this->expectExceptionObject(ShoppingListRowException::notFound('shopping-list-row-id-not-found'));

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedTickLineOnShoppingUnknownShoppingList(): void
    {
        // Arrange
        $command = new TickLineOnShoppingListCommand('shopping-list-row-id');

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound());

        // Act
        ($this->handler)($command);
    }
}
