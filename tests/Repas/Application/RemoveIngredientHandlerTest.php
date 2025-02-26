<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\RemoveIngredient\RemoveIngredientCommand;
use Repas\Repas\Application\RemoveIngredient\RemoveIngredientHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;

class RemoveIngredientHandlerTest extends TestCase
{
    private readonly RemoveIngredientHandler $handler;
    private readonly IngredientRepository $ingredientRepository;
    private readonly RecipeRepository $recipeRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly ConversionRepository $conversionRepository;


    protected function setUp(): void
    {
        $this->ingredientRepository = new IngredientInMemoryRepository([
            new IngredientBuilder()->isSugar()->build(),
            new IngredientBuilder()->isFloor()->build(),
            new IngredientBuilder()->isPasta()->build(),
        ]);
        $this->recipeRepository = new RecipeInMemoryRepository([
            new RecipeBuilder()->isPastaCarbonara()->build(),
        ]);
        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            new ShoppingListBuilder()->addIngredient(new IngredientBuilder()->isFloor())->build()
        ]);
        $this->conversionRepository = new ConversionInMemoryRepository([
           new ConversionBuilder()
               ->withIngredient(new IngredientBuilder()->isSugar())
               ->withStartUnit(new UnitBuilder()->isBox()->build())
               ->withEndUnit(new UnitBuilder()->isGramme()->build())
               ->withCoefficient(1000)
               ->build(),
        ]);
        $this->handler = new RemoveIngredientHandler(
            $this->ingredientRepository,
            $this->recipeRepository,
            $this->shoppingListRepository,
            $this->conversionRepository,
        );
    }


    public function testSuccessfullyHandleRemoveIngredient(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('sucre');

        // Act
        ($this->handler)($command);

        // Assert
        $conversions = $this->conversionRepository->findByIngredient(new IngredientBuilder()->isSugar()->build())->filter(fn(Conversion $conversion) => $conversion->getIngredient() !== null);
        $this->assertCount(0, $conversions);

        $this->expectExceptionObject(IngredientException::notFound('sucre'));
        $this->ingredientRepository->findOneBySlug('sucre');
    }

    public function testFailedHandleRemoveIngredientUnknownIngredient(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('non-existant');

        // Assert
        $this->expectExceptionObject(IngredientException::notFound('non-existant'));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleRemoveIngredientRecipeWithIngredientExist(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('pate');

        // Assert
        $this->expectExceptionObject(IngredientException::cannotBeRemoveExistInRecipe('pate'));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleRemoveIngredientShoppingListWithIngredientExist(): void
    {
        // Arrange
        $command = new RemoveIngredientCommand('farine');

        // Assert
        $this->expectExceptionObject(IngredientException::cannotBeRemoveExistInShoppingList('farine'));

        // Act
        ($this->handler)($command);
    }
}
