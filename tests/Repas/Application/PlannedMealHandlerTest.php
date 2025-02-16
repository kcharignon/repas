<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\PlannedMeal\PlannedMealHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;

class PlannedMealHandlerTest extends TestCase
{
    private readonly PlannedMealHandler $handler;
    private readonly UserRepository $userRepository;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly RecipeRepository $recipeRepository;
    private readonly ConversionService $conversionService;
    private readonly ConversionRepository $conversionRepository;
    private readonly UnitRepository $unitRepository;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository();
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->recipeRepository = new RecipeInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository();
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()
                ->withIngredient(new IngredientBuilder()->isThickCremeFraiche())
                ->withStartUnit(new UnitBuilder()->isGramme())
                ->withEndUnit(new UnitBuilder()->isCentiliter())
                ->withCoefficient(5)
                ->build(),
        ]);
        $this->conversionService = new ConversionService(
            $this->conversionRepository,
            $this->unitRepository,
        );

        $this->handler = new PlannedMealHandler(
            $this->userRepository,
            $this->shoppingListRepository,
            $this->recipeRepository,
            $this->conversionService,
        );
    }

    public function testHandleSuccessfullyPlannedMeal(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new PlannedMealCommand(
            $user->getId(),
            $recipe->getId(),
        );

        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user);
        $this->assertTrue($shoppingList->hasRecipe($recipe));
    }

    public function testHandleFailedPlannedMealNotActivatedShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $command = new PlannedMealCommand(
            $user->getId(),
            $recipe->getId(),
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedPlannedMealRecipeNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new PlannedMealCommand(
            $user->getId(),
            $recipe->getId(),
        );

        // Assert
        $this->expectExceptionObject(RecipeException::notFound($recipe->getId()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedPlannedMealUserNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new PlannedMealCommand(
            $user->getId(),
            $recipe->getId(),
        );

        // Assert
        $this->expectExceptionObject(UserException::notFound($user->getId()));

        // Act
        ($this->handler)($command);
    }
}
