<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\MealBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class PlannedMealHandlerTest extends TestCase
{
    private readonly PlannedMealHandler $handler;
    private readonly ShoppingListRepository $shoppingListRepository;
    private readonly RecipeRepository $recipeRepository;
    private readonly ConversionService $conversionService;
    private readonly ConversionRepository $conversionRepository;
    private readonly UnitRepository $unitRepository;

    protected function setUp(): void
    {
        $this->shoppingListRepository = new ShoppingListInMemoryRepository();
        $this->recipeRepository = new RecipeInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository();
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
        $this->conversionService = new ConversionService(
            $this->conversionRepository,
            $this->unitRepository,
        );

        $this->handler = new PlannedMealHandler(
            $this->shoppingListRepository,
            $this->recipeRepository,
            $this->conversionService,
            new UuidGenerator()
        );
    }

    public function testHandleSuccessfullyPlannedMeal(): void
    {
        // Arrange
        $user = new UserBuilder()->withServing(2)->build();
        $recipe = new RecipeBuilder()->isBasqueCake()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new PlannedMealCommand(
            $user->getId(),
            $recipe->getId(),
            $user->getDefaultServing(),
        );

        // Act
        ($this->handler)($command);

        // Assert
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user->getId());
        $this->assertTrue($shoppingList->hasRecipe($recipe));
        $expected = new MealBuilder()
            ->withRecipe($recipe)
            ->withServing($user->getDefaultServing())
            ->withShoppingListId($shoppingList->getId())
            ->build();
        $actual = $shoppingList->getMeals()->reset();
        RepasAssert::assertMeal($expected, $actual, ['id']);
        // On contrôle la quantité pour chaque ingredient
        $recipe = new RecipeBuilder()->isBasqueCake()->build();
        foreach ($recipe->getRows() as $recipeRow) {
            $ingredient = $recipeRow->getIngredient();
            $coefficient = $user->getDefaultServing() / $recipe->getServing();
            $actualQuantity = $shoppingList->getRows()->find(fn(ShoppingListRow $row) => $row->getIngredientSlug() === $ingredient->getSlug())->getQuantity();
            // On convertie dans l'unité d'achat et on multiplie par le coef
            $expectedQuantity = $this->conversionService->convertTo($ingredient, $recipeRow->getQuantity(), $recipeRow->getUnit(), $ingredient->getDefaultPurchaseUnit()) * $coefficient;
            $this->assertEquals($expectedQuantity, $actualQuantity, "Ingredient '{$ingredient->getName()}' have not the right quantity");
        }
    }

    public function testHandleFailedPlannedMealNotActivatedShoppingList(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $command = new PlannedMealCommand(
            $user->getId(),
            $recipe->getId(),
            $user->getDefaultServing(),
        );

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedPlannedMealRecipeOfAnotherUser(): void
    {
        // Arrange : la recette d'un autre utilisateur est traitée comme inconnue, la liste ne change pas
        $user = new UserBuilder()->build();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor(new UserBuilder()->build())->build();
        $this->recipeRepository->save($recipe);
        $this->shoppingListRepository->save(new ShoppingListBuilder()->withOwner($user)->build());
        $command = new PlannedMealCommand($user->getId(), $recipe->getId(), $user->getDefaultServing());

        // Act
        try {
            ($this->handler)($command);
            $this->fail("La recette d'un autre utilisateur ne se planifie pas.");
        } catch (RecipeException $exception) {
            // Assert
            $this->assertEquals(RecipeException::notFound($recipe->getId()), $exception);
            $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($user->getId());
            $this->assertCount(0, $shoppingList->getMeals());
            $this->assertCount(0, $shoppingList->getRows());
        }
    }

    public function testHandleFailedPlannedMealRecipeNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new PlannedMealCommand(
            $user->getId(),
            $recipe->getId(),
            $user->getDefaultServing(),
        );

        // Assert
        $this->expectExceptionObject(RecipeException::notFound($recipe->getId()));

        // Act
        ($this->handler)($command);
    }


    public function testRefusesAnOutdatedVersionAndPlansNothing(): void
    {
        // Arrange : la liste est à la version 3, le client a lu la 2
        $user = new UserBuilder()->withServing(2)->build();
        $recipe = new RecipeBuilder()->isBasqueCake()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->withVersion(3)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new PlannedMealCommand($user->getId(), $recipe->getId(), $user->getDefaultServing(), expectedVersion: 2);

        // Assert
        $this->expectExceptionObject(ShoppingListException::outdated($shoppingList->getId()));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertCount(0, $shoppingList->getMeals());
        }
    }

    public function testPlansWhenTheExpectedVersionIsTheCurrentOne(): void
    {
        // Arrange
        $user = new UserBuilder()->withServing(2)->build();
        $recipe = new RecipeBuilder()->isBasqueCake()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($user)->withVersion(3)->build();
        $this->shoppingListRepository->save($shoppingList);
        $command = new PlannedMealCommand($user->getId(), $recipe->getId(), $user->getDefaultServing(), expectedVersion: 3);

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertTrue($shoppingList->hasRecipe($recipe));
    }

    public function testDoesNotTouchASentList(): void
    {
        // Arrange : le propriétaire n'a qu'une liste envoyée au drive (ADR 0023)
        $user = new UserBuilder()->build();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->recipeRepository->save($recipe);
        $sent = new ShoppingListBuilder()->withOwner($user)->addIngredient(new IngredientBuilder()->isEgg())->withStatus(ShoppingListStatus::SENT)->build();
        $this->shoppingListRepository->save($sent);

        // Assert
        $this->expectExceptionObject(ShoppingListException::activeShoppingListNotFound());

        // Act
        try {
            ($this->handler)(new PlannedMealCommand($user->getId(), $recipe->getId(), $user->getDefaultServing()));
        } finally {
            $this->assertCount(0, $sent->getMeals());
            $this->assertCount(1, $sent->getRows());
        }
    }
}
