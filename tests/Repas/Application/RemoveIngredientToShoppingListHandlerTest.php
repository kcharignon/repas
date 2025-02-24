<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListHandler;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class RemoveIngredientToShoppingListHandlerTest extends TestCase
{
    private RemoveIngredientToShoppingListHandler $handler;
    private UserRepository $userRepository;
    private ShoppingListRepository $shoppingListRepository;
    private IngredientRepository $ingredientRepository;

    private User $user;
    private Ingredient $ingredient;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->handler = new RemoveIngredientToShoppingListHandler(
            $this->userRepository,
            $this->shoppingListRepository,
            $this->ingredientRepository,
        );

        $this->user = new UserBuilder()->withId('user-id')->build();
        $this->userRepository->save($this->user);
        $this->ingredient = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($this->ingredient);
    }

    public function testHandleSuccessfullyRemoveIngredientToShoppingListHandlerRemoveIngredient(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->addIngredient($this->ingredient)
            ->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new RemoveIngredientToShoppingListCommand(
            'user-id',
            'pate',
        );


        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertEmpty($shoppingList->getIngredients());
        $this->assertEmpty($shoppingList->getRows());
    }

    public function testHandleSuccessfullyRemoveIngredientToShoppingListHandlerReduceQuantity(): void
    {
        // Arrange
        $shoppingList = new ShoppingListBuilder()
            ->withId('shopping-list-id')
            ->withOwner($this->user)
            ->addIngredient($this->ingredient)
            ->addIngredient($this->ingredient)
            ->addIngredient($this->ingredient)
            ->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new RemoveIngredientToShoppingListCommand(
            'user-id',
            'pate',
        );

        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneById('shopping-list-id');
        $this->assertCount(1, $shoppingList->getIngredients());
        $this->assertCount(1, $shoppingList->getRows());
        $this->assertEquals(2, $shoppingList->getIngredients()->find(fn(ShoppingListIngredient $spi) => $spi->getIngredient()->isEqual($this->ingredient))->getQuantity());
        $this->assertEquals(2, $shoppingList->getRows()->find(fn(ShoppingListRow $spr) => $spr->getIngredient()->isEqual($this->ingredient))->getQuantity());
    }

    public function testFailedHandleRemoveIngredientToShoppingListShoppingListNotFound(): void
    {
        // Arrange
        $command = new RemoveIngredientToShoppingListCommand(
            'user-id',
            'pate',
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }

}
