<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Builder\RecipeBuilder;
use Repas\Tests\Builder\RecipeRowBuilder;
use Repas\Tests\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Interface\UserRepository;

class RecipeRepositoryTest extends DatabaseTestCase
{
    private RecipeRepository $recipeRepository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeRepository = static::getContainer()->get(RecipeRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $user = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);

        // Arrange
        $recipe->setServing(10);
        $recipe->setName("nouveau petit nom");
        $recipe->setType(new RecipeTypeBuilder()->isDessert()->build());
        $recipe->setRows(Tab::fromArray(new RecipeRowBuilder()->setRecipeId($recipe->getId())->build()));

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);
    }

    public function testFindByAuthor(): void
    {
        // Arrange
        $author = $this->userRepository->findOneByEmail('alexiane.sichi@gmail.com');

        // Act
        $recipes = $this->recipeRepository->findByAuthor($author);

        // Assert
        $this->assertCount(71, $recipes);
        RepasAssert::assertTab(Tab::newEmptyTyped(Recipe::class), $recipes);
    }
}
