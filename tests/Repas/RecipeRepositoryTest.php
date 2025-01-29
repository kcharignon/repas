<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Builder\RecipeBuilder;
use Repas\Tests\Builder\RecipeRowBuilder;
use Repas\Tests\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;

class RecipeRepositoryTest extends DatabaseTestCase
{
    private RecipeRepository $recipeRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeRepository = static::getContainer()->get(RecipeRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->build();

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
}
