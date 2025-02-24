<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Application\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

class UpdateServingMealHandlerTest extends TestCase
{
    private UpdateServingMealHandler $handler;
    private ShoppingListRepository $shoppingListRepository;
    private ConversionService $conversionService;
    private ConversionRepository $conversionRepository;
    private UnitRepository $unitRepository;
    private ShoppingList $shoppingList;
    private Recipe $recipe;

    protected function setUp(): void
    {
        $user = new UserBuilder()->withId("unique-id")->build();
        $this->recipe = new RecipeBuilder()->isBasqueCake()->build();
        $this->shoppingList = new ShoppingListBuilder()
            ->withOwner($user)
            ->addRecipe($this->recipe)
            ->build();

        $this->shoppingListRepository = new ShoppingListInMemoryRepository([
            $this->shoppingList
        ]);
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()
                ->withoutIngredient()
                ->withStartUnit(new UnitBuilder()->isKilo())
                ->withEndUnit(new UnitBuilder()->isGramme())
                ->withCoefficient(1000)
                ->build(),
            new ConversionBuilder()
                ->withIngredient(new IngredientBuilder()->isButter())
                ->withStartUnit(new UnitBuilder()->isBlock())
                ->withEndUnit(new UnitBuilder()->isGramme())
                ->withCoefficient(250)
                ->build(),
        ]);
        $this->unitRepository = new UnitInMemoryRepository();
        $this->conversionService = new ConversionService(
            $this->conversionRepository,
            $this->unitRepository
        );

        $this->handler = new UpdateServingMealHandler(
            $this->shoppingListRepository,
            $this->conversionService,
        );
    }

    public function testHandleSuccessfullyUpdateServingMeal(): void
    {
        // Arrange
        $mealId = $this->shoppingList->getMeals()->find(fn(Meal $meal) => $meal->hasRecipe($this->recipe))->getId();
        $command = new UpdateServingMealCommand(
            $mealId,
            12
        );

        // Act
        ($this->handler)($command);

        // Assert
        $actualMeal = $this->shoppingListRepository->findOneById($this->shoppingList->getId())
            ->getMeals()->find(fn(Meal $meal) => $meal->getId() === $mealId);
        $this->assertEquals(12, $actualMeal->getServing());
    }
}
