<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeHandler;
use Repas\Repas\Application\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UpdateRecipeHandlerTest extends TestCase
{
    private readonly UpdateRecipeHandler $handler;
    private readonly RecipeRepository $recipeRepository;
    private readonly RecipeTypeRepository $recipeTypeRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly UnitRepository $unitRepository;

    protected function setUp(): void
    {
        $this->recipeRepository = new RecipeInMemoryRepository();
        $this->recipeTypeRepository = new RecipeTypeInMemoryRepository([
            new RecipeTypeBuilder()->isMeal()->build(),
        ]);
        $this->ingredientRepository = new IngredientInMemoryRepository([
            new IngredientBuilder()->isPasta()->build(),
            new IngredientBuilder()->isParmesan()->build(),
        ]);
        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isGramme()->build(),
        ]);

        $this->handler = new UpdateRecipeHandler(
            $this->recipeRepository,
            $this->recipeTypeRepository,
            $this->ingredientRepository,
            $this->unitRepository,
        );
    }

    public function testSuccessfullyHandleUpdateRecipe(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->build();
        $this->recipeRepository->save($recipe);
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo. épurées',
            450,
            Tab::fromArray(
                new UpdateRecipeRowSubCommand(
                    'pate',
                    'gramme',
                    5000,
                ),
                new UpdateRecipeRowSubCommand(
                    'parmesan',
                    'gramme',
                    400,
                ),
            ),
            'plat'
        );


        // Act
        ($this->handler)($command);

        // Assert
        $expectedRecipe = new RecipeBuilder()
            ->withId($recipe->getId())
            ->withName('Pates carbo. épurées')
            ->withServing(450)
            ->withAuthor($recipe->getAuthor())
            ->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()
                ->withRecipeId($recipe->getId())
                ->withIngredient(new IngredientBuilder()->isPasta())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(5000)
            )
            ->addRow(new RecipeRowBuilder()
                ->withRecipeId($recipe->getId())
                ->withIngredient(new IngredientBuilder()->isParmesan())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(400)
            )
            ->build();
        $actual = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($actual, $expectedRecipe, ["RecipeRow" => ["id"]]);
    }


    public function testFailedHandleUpdateRecipeUnknownRecipe(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->build();
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo. épurées',
            450,
            Tab::fromArray(
                new UpdateRecipeRowSubCommand(
                    'pate',
                    'gramme',
                    5000,
                ),
                new UpdateRecipeRowSubCommand(
                    'parmesan',
                    'gramme',
                    400,
                ),
            ),
            'plat'
        );

        // Assert
        $this->expectExceptionObject(RecipeException::notFound($recipe->getId()));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleUpdateRecipeUnknownRecipeType(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->build();
        $this->recipeRepository->save($recipe);
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo. épurées',
            450,
            Tab::fromArray(
                new UpdateRecipeRowSubCommand(
                    'pate',
                    'gramme',
                    5000,
                ),
                new UpdateRecipeRowSubCommand(
                    'parmesan',
                    'gramme',
                    400,
                ),
            ),
            'non-existant'
        );

        // Assert
        $this->expectExceptionObject(RecipeException::typeNotFound('non-existant'));

        // Act
        ($this->handler)($command);

    }
}
