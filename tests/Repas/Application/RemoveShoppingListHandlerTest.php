<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Repas\Application\RemoveShoppingList\RemoveShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class RemoveShoppingListHandlerTest extends TestCase
{
    private readonly RemoveShoppingListHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->handler = new RemoveShoppingListHandler(
            $this->shoppingListRepository,
        );
    }

    public function testHandleSuccessfullyRemoveShoppingList(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()->withId('shopping-list-id')->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new RemoveShoppingListCommand('shopping-list-id');

        // Act
        ($this->handler)($command);

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound());
        $this->shoppingListRepository->findOneById('shopping-list-id');
    }

    public function testHandleFailedRemoveShoppingListUnknownShoppingList(): void
    {
        // Arrange
        $command = new RemoveShoppingListCommand('shopping-list-id');

        // Assert
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound());

        // Act
        ($this->handler)($command);
    }
}
