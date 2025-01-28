<?php

namespace Repas;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Tests\Builder\IngredientBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class IngredientRepositoryTest extends DatabaseTestCase
{
    private IngredientRepository $ingredientRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredientRepository = static::getContainer()->get(IngredientRepository::class);
    }

    public function testInsertAndUpdateAndFindBySlug(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->build();

        // Act
        $this->ingredientRepository->save($ingredient);

        // Assert
        $loadedIngredient = $this->ingredientRepository->findBySlug($ingredient->getSlug());
        $this->assertEquals($ingredient, $loadedIngredient);
    }


}
