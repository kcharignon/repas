<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Infrastructure\DataFixture\RecipeFixture;
use Repas\Tests\Builder\RecipeBuilder;
use Repas\Tests\Builder\ShoppingListBuilder;
use Repas\Tests\Builder\UserBuilder;
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
        $shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->addRecipe($recipes->shift())
            ->build()
        ;

        // Act
        $this->shoppingListRepository->save($shoppingList);

        // Assert
        $actual = $this->shoppingListRepository->getOneById($shoppingList->getId());
        RepasAssert::assertShoppingList($shoppingList, $actual);

    }


}
