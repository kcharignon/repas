<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListHandler;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Domain\Interface\UserRepository;

class RemoveIngredientToShoppingListHandlerTest extends TestCase
{
    private RemoveIngredientToShoppingListHandler $handler;
    private UserRepository $userRepository;
    private ShoppingListRepository $shoppingListRepository;
    private IngredientRepository $ingredientRepository;

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
    }

    public function testHandleSuccessfullyRemoveIngredientToShoppingListHandlerRemoveIngredient(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $ingredient = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($ingredient);
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->addIngredient($ingredient)
            ->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new RemoveIngredientToShoppingListCommand(
            $user->getId(),
            $ingredient->getSlug(),
        );


        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneById($shoppingList->getId());
        $this->assertEmpty($shoppingList->getIngredients());
        $this->assertEmpty($shoppingList->getRows());
    }

    public function testHandleSuccessfullyRemoveIngredientToShoppingListHandlerReduceQuantity(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $ingredient = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($ingredient);
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->addIngredient($ingredient)
            ->addIngredient($ingredient)
            ->addIngredient($ingredient)
            ->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new RemoveIngredientToShoppingListCommand(
            $user->getId(),
            $ingredient->getSlug(),
        );


        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneById($shoppingList->getId());
        $this->assertCount(1, $shoppingList->getIngredients());
        $this->assertCount(1, $shoppingList->getRows());
        $this->assertEquals(2, $shoppingList->getIngredients()->find(fn(ShoppingListIngredient $spi) => $spi->getIngredient()->isEqual($ingredient))->getQuantity());
        $this->assertEquals(2, $shoppingList->getRows()->find(fn(ShoppingListRow $spr) => $spr->getIngredient()->isEqual($ingredient))->getQuantity());
    }

}
