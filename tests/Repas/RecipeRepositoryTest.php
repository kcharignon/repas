<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Tests\Builder\RecipeBuilder;
use Repas\Tests\Builder\RecipeRowBuilder;
use Repas\Tests\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

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
        $this->assertEquals('pates carbonara', $recipeLoaded->getName());
        $this->assertEquals('johndoe@example.com', $recipeLoaded->getAuthor()->getEmail());
        $this->assertEquals('plat', $recipeLoaded->getType()->getSlug());
        $this->assertEquals(4, $recipeLoaded->getServing());
        $this->assertCount(1, $recipeLoaded->getRows()->filter(fn(RecipeRow $row) => $row->getIngredient()->getSlug() === 'pate'));
        $this->assertCount(1, $recipeLoaded->getRows()->filter(fn(RecipeRow $row) => $row->getIngredient()->getSlug() === 'oeuf'));
        $this->assertCount(1, $recipeLoaded->getRows()->filter(fn(RecipeRow $row) => $row->getIngredient()->getSlug() === 'creme-fraiche-epaisse'));
        $this->assertCount(1, $recipeLoaded->getRows()->filter(fn(RecipeRow $row) => $row->getIngredient()->getSlug() === 'lardon'));
        $this->assertCount(1, $recipeLoaded->getRows()->filter(fn(RecipeRow $row) => $row->getIngredient()->getSlug() === 'parmesan'));

    }


}
