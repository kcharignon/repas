<?php

namespace Repas\Tests\Repas;


use Builder\RecipeBuilder;
use Repas\Repas\Domain\Interface\RecipeRepository;
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
        $recipe  = new RecipeBuilder()->build();
    }


}
