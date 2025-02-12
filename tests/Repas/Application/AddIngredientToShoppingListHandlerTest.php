<?php

namespace Repas\Tests\Repas\Application;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\AddIngredientToShoppingList\AddIngredientToShoppingListHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;

class AddIngredientToShoppingListHandlerTest extends TestCase
{
    private readonly AddIngredientToShoppingListHandler $handler;
    private readonly UserRepository $userRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {

        $this->userRepository = new UserInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();

        $this->handler =  new AddIngredientToShoppingListHandler(
            userRepository: $this->userRepository,
            shoppingListRepository: $this->shoppingListRepository,
            ingredientRepository: $this->ingredientRepository,
        );
    }

    public function testHandleSuccessfullyAddsIngredientToShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Act
        ($this->handler)($command);

        //Assert
        $this->assertCount(1, $shoppingList->getIngredients());
        $this->assertCount(1, $shoppingList->getRows());
        $this->assertNotNull($shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($egg)));
        $this->assertEquals(1, $shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($egg))?->getQuantity());
    }

    public function testHandleAddsIngredientToShoppingListWithUnknownUser(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new AddIngredientToShoppingListCommand('unknown', $egg->getSlug());

        // Assert
        $this->expectExceptionObject(UserException::NotFound('unknown'));

        // Act
        ($this->handler)($command);
    }

    public function testHandleAddsIngredientToShoppingListWithMissingShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }

    public function testHandleAddsIngredientToShoppingListWithUnknownIngredient(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $egg = new IngredientBuilder()->isEgg()->build();
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Assert
        $this->expectExceptionObject(IngredientException::notFound());

        // Act
        ($this->handler)($command);
    }
}
