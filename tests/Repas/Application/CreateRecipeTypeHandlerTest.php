<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CreateRecipeType\CreateRecipeTypeCommand;
use Repas\Repas\Application\CreateRecipeType\CreateRecipeTypeHandler;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class CreateRecipeTypeHandlerTest extends TestCase
{
    private CreateRecipeTypeHandler $handler;
    private RecipeTypeRepository $recipeTypeRepository;

    protected function setUp(): void
    {
        $this->recipeTypeRepository = new RecipeTypeInMemoryRepository();
        $this->handler = new CreateRecipeTypeHandler($this->recipeTypeRepository);
    }

    public function testCreateRecipeTypeSuccess(): void
    {
        // Arrange
        $command = new CreateRecipeTypeCommand(
            "Boisson",
            "images/cocktail.jpg",
            13,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expect = new RecipeTypeBuilder()
            ->withName("Boisson")
            ->withImage("images/cocktail.jpg")
            ->withOrder(3)
            ->build();
        $actual = $this->recipeTypeRepository->findOneBySlug("boisson");
        RepasAssert::assertRecipeType($expect, $actual);
    }


}
