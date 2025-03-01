<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateRecipeType\UpdateRecipeTypeCommand;
use Repas\Repas\Application\UpdateRecipeType\UpdateRecipeTypeHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UpdateRecipeTypeHandlerTest extends TestCase
{
    private UpdateRecipeTypeHandler $handler;
    private RecipeTypeRepository $recipeTypeRepository;

    protected function setUp(): void
    {
        $this->recipeTypeRepository = new RecipeTypeInMemoryRepository([
            new RecipeTypeBuilder()->isDessert()->build(),
        ]);
        $this->handler = new UpdateRecipeTypeHandler($this->recipeTypeRepository);
    }

    public function testUpdateRecipeTypeSuccess(): void
    {
        // Arrange
        $command = new UpdateRecipeTypeCommand(
            "dessert",
            "Boisson",
            "images/cocktail.jpg",
            13,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expect = new RecipeTypeBuilder()
            ->withSlug("dessert")
            ->withName("Boisson")
            ->withImage("images/cocktail.jpg")
            ->withOrder(3)
            ->build();
        $actual = $this->recipeTypeRepository->findOneBySlug("dessert");
        RepasAssert::assertRecipeType($expect, $actual);
    }

    public function testUpdateRecipeTypeFailedRecipeTypeNotFound(): void
    {
        // Arrange
        $command = new UpdateRecipeTypeCommand(
            "not-found",
            "Boisson",
            "images/cocktail.jpg",
            13,
        );

        // Assert
        $this->expectExceptionObject(RecipeException::typeNotFound('not-found'));

        // Act
        ($this->handler)($command);
    }
}
