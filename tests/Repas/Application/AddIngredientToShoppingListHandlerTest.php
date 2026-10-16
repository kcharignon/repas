<?php

namespace Repas\Tests\Repas\Application;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListHandler;
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

class AddIngredientToShoppingListHandlerTest extends TestCase
{
    private readonly AddIngredientToShoppingListHandler $handler;
    private readonly IngredientRepository $ingredientRepository;
    private readonly ShoppingListRepository $shoppingListRepository;

    protected function setUp(): void
    {

        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();

        $this->handler =  new AddIngredientToShoppingListHandler(
            shoppingListRepository: $this->shoppingListRepository,
            ingredientRepository: $this->ingredientRepository,
        );
    }

    public function testHandleSuccessfullyAddsIngredientToShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
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
        $this->assertNotNull($shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($egg->getSlug())));
        $this->assertEquals(1, $shoppingList->getIngredients()->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($egg->getSlug()))?->getQuantity());
    }

    public function testHandleAddsIngredientToShoppingListWithMissingShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }

    public function testHandleAddsACustomIngredientOfTheOwner(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $butter = new IngredientBuilder()->isButter()->withCreator($user, true)->build();
        $this->ingredientRepository->save($butter);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        ($this->handler)(new AddIngredientToShoppingListCommand($user->getId(), $butter->getSlug()));

        // Assert
        $this->assertCount(1, $shoppingList->getRows());
    }

    public function testHandleRefusesTheCustomIngredientOfAnotherUserAsUnknown(): void
    {
        // Arrange : l'ingrédient personnalisé d'un autre n'est ni à l'utilisateur ni commun
        $user = new UserBuilder()->build();
        $butter = new IngredientBuilder()->isButter()->withCreator(new UserBuilder()->build(), true)->build();
        $this->ingredientRepository->save($butter);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        // Act
        try {
            ($this->handler)(new AddIngredientToShoppingListCommand($user->getId(), $butter->getSlug()));
            $this->fail("L'ingrédient d'un autre utilisateur ne se pose pas dans la liste.");
        } catch (IngredientException $exception) {
            // Assert
            $this->assertEquals(IngredientException::notFound($butter->getSlug()), $exception);
            $this->assertCount(0, $shoppingList->getRows());
            $this->assertCount(0, $shoppingList->getIngredients());
        }
    }

    public function testHandleAddsIngredientToShoppingListWithUnknownIngredient(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);

        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug());

        // Assert
        $this->expectExceptionObject(IngredientException::notFound($egg->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAnOutdatedVersionAndAddsNothing(): void
    {
        // Arrange : la liste est à la version 1, le client croit à la 2
        $user = new UserBuilder()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new AddIngredientToShoppingListCommand($user->getId(), $egg->getSlug(), expectedVersion: 2);

        // Assert
        $this->expectExceptionObject(ShoppingListException::outdated($shoppingList->getId()));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertCount(0, $shoppingList->getIngredients());
        }
    }
}
