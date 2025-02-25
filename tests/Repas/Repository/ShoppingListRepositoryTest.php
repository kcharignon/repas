<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Interface\UserRepository;

class ShoppingListRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingListRepository;
    private UserRepository $userRepository;
    private RecipeRepository $recipeRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingListRepository = static::getContainer()->get(ShoppingListRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
        $this->recipeRepository = static::getContainer()->get(RecipeRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        // Récupère l'utilisateur Bich
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        // Récupère toutes les recettes de l'utilisateur
        $recipes = $this->recipeRepository->findByAuthor($user);
        $firstRecipe = $recipes->shift();
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->addRecipe($firstRecipe)
            ->build()
        ;

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        $secondRecipe = $recipes->shift();
        $shoppingList->addMeal($secondRecipe);

        // Act
        $this->shoppingListRepository->save($shoppingList);

        //Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        $shoppingList->removeMeal($firstRecipe);

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        foreach ($shoppingList->getIngredients() as $shopListIngredient) {
            $shoppingList->addRow($shopListIngredient->getIngredient(), $shopListIngredient->getQuantity());
        }

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);
    }

    public function testFindByOwner(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');

        // Act
        $shoppingLists = $this->shoppingListRepository->findByOwner($user);

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(ShoppingList::class), $shoppingLists);
        $this->assertCount(3, $shoppingLists);
        foreach ($shoppingLists as $shoppingList) {
            RepasAssert::assertUser($shoppingList->getOwner(), $user);
        }
    }

    public function testFindOneActivateByOwner(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');

        // Act
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user);

        // Assert
        $this->assertInstanceOf(ShoppingList::class, $shoppingList);
        $this->assertEquals(ShoppingListStatus::ACTIVE, $shoppingList->getStatus());
        RepasAssert::assertUser($shoppingList->getOwner(), $user);
    }
}
