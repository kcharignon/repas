<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Builder\ShoppingListBuilder;
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
        dump($firstRecipe->getRows()->map(fn(RecipeRow $row) => sprintf("%s : %d %s", $row->getIngredient()->getSlug(), (int)$row->getQuantity(), $row->getUnit()->getSymbol())));
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->unLocked()
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
        dump($secondRecipe->getRows()->map(fn(RecipeRow $row) => sprintf("%s : %d %s", $row->getIngredient()->getSlug(), (int)$row->getQuantity(), $row->getUnit()->getSymbol())));
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


    }

    public function testFindByOwner(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');

        // Act
        $shoppingLists = $this->shoppingListRepository->findByOwner($user);

        // Assert
        RepasAssert::assertTab(Tab::newEmptyTyped(ShoppingList::class), $shoppingLists);
        $this->assertCount(4, $shoppingLists);
        foreach ($shoppingLists as $shoppingList) {
            RepasAssert::assertUser($shoppingList->getOwner(), $user);
        }
    }

    public function testFindOneActiveByOwner(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');

        // Act
        $shoppingList = $this->shoppingListRepository->findOneActiveByOwner($user);

        // Assert
        $this->assertInstanceOf(ShoppingList::class, $shoppingList);
        $this->assertFalse($shoppingList->isLocked());
        RepasAssert::assertUser($shoppingList->getOwner(), $user);
    }
}
