<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
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
        $shoppingListBuilder = new ShoppingListBuilder()
            ->withOwner($user)
            ->addRecipe($recipes->shift())
        ;
        $shoppingList = $shoppingListBuilder->build();

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->findOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

        // Arrange
        $shoppingListBuilder->addRecipe($recipes->shift());

        // Act
        $this->shoppingListRepository->save($shoppingList);

        //Assert
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
