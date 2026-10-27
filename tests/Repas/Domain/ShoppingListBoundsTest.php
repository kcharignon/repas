<?php

namespace Repas\Tests\Repas\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Tests\Helper\Builder\RecipeBuilder;

/**
 * Les bornes d'une liste de courses : des quantités toujours finies, un nombre de personnes de 1 à 100 par repas, au plus 500 repas.
 */
class ShoppingListBoundsTest extends TestCase
{
    private const string OWNER = 'owner-id';

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteQuantities(): iterable
    {
        yield 'infinie' => [INF];
        yield 'moins l\'infini' => [-INF];
        yield 'indéfinie' => [NAN];
    }

    #[DataProvider('nonFiniteQuantities')]
    public function testARowCannotBeCreatedWithANonFiniteQuantity(float $quantity): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::invalidQuantity());

        // When
        ShoppingListRow::create('list', 'oeuf', 'unite', $quantity);
    }

    #[DataProvider('nonFiniteQuantities')]
    public function testAQuantityCannotBeAddedToARowIfTheTotalIsNotFinite(float $quantity): void
    {
        // Given
        $row = ShoppingListRow::create('list', 'oeuf', 'unite', 6.0);

        // Then
        $this->expectExceptionObject(ShoppingListException::invalidQuantity());

        // When
        $row->addQuantity($quantity);
    }

    public function testTwoHugeQuantitiesCannotOverflowARow(): void
    {
        // Given
        $row = ShoppingListRow::create('list', 'oeuf', 'unite', PHP_FLOAT_MAX);

        // Then
        $this->expectExceptionObject(ShoppingListException::invalidQuantity());

        // When
        $row->addQuantity(PHP_FLOAT_MAX);
    }

    public function testARowKeepsItsQuantityWhenAnAdditionIsRefused(): void
    {
        // Given
        $row = ShoppingListRow::create('list', 'oeuf', 'unite', 6.0);

        // When
        try {
            $row->addQuantity(INF);
            $this->fail('Une quantité infinie doit être refusée.');
        } catch (ShoppingListException) {
        }

        // Then
        $this->assertSame(6.0, $row->getQuantity());
    }

    #[DataProvider('nonFiniteQuantities')]
    public function testAnIngredientLineRefusesANonFiniteQuantity(float $quantity): void
    {
        // Given
        $line = ShoppingListIngredient::create('list', 'oeuf', 'unite', 1.0);

        // Then
        $this->expectExceptionObject(ShoppingListException::invalidQuantity());

        // When
        $line->addQuantity($quantity);
    }

    public function testAnIngredientLineCannotBeCreatedWithANonFiniteQuantity(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::invalidQuantity());

        // When
        ShoppingListIngredient::create('list', 'oeuf', 'unite', NAN);
    }

    public function testTheQuantityOfARowCanStillGoDownToZeroOrBelow(): void
    {
        // Given : retirer plus qu'il n'y a est un cas ordinaire (la ligne disparaît ensuite)
        $row = ShoppingListRow::create('list', 'oeuf', 'unite', 2.0);

        // When
        $row->subtractQuantity(5.0);

        // Then
        $this->assertSame(-3.0, $row->getQuantity());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidServings(): iterable
    {
        yield 'zéro' => [0];
        yield 'négatif' => [-2];
        yield 'cent un' => [101];
    }

    #[DataProvider('invalidServings')]
    public function testAMealCannotBeCreatedForAnInvalidNumberOfPeople(int $serving): void
    {
        // Then
        $this->expectExceptionObject(MealException::invalidServing());

        // When
        Meal::create('meal', 'list', 'recipe', $serving);
    }

    #[DataProvider('invalidServings')]
    public function testAMealCannotBeChangedToAnInvalidNumberOfPeople(int $serving): void
    {
        // Given
        $meal = Meal::create('meal', 'list', 'recipe', 4);

        // Then
        $this->expectExceptionObject(MealException::invalidServing());

        // When
        $meal->setServing($serving);
    }

    public function testAMealGoesFromOneToAHundredPeople(): void
    {
        // When / Then
        $this->assertSame(1, Meal::create('meal', 'list', 'recipe', 1)->getServing());
        $this->assertSame(100, Meal::create('meal', 'list', 'recipe', 100)->getServing());
    }

    public function testAListPlansUpToFiveHundredMeals(): void
    {
        // Given
        $list = $this->list();

        // When
        for ($i = 0; $i < ShoppingList::MAX_MEALS; $i++) {
            $list->addMeal('meal-' . $i, $this->recipe($i), 4);
        }

        // Then
        $this->assertSame(500, $list->countRecipes());
    }

    public function testTheFiveHundredAndFirstMealIsRefusedAndNothingIsAdded(): void
    {
        // Given
        $list = $this->list();
        for ($i = 0; $i < ShoppingList::MAX_MEALS; $i++) {
            $list->addMeal('meal-' . $i, $this->recipe($i), 4);
        }

        // When
        try {
            $list->addMeal('one-more', $this->recipe(ShoppingList::MAX_MEALS), 4);
            $this->fail('Le repas de trop doit être refusé.');
        } catch (ShoppingListException $exception) {
            // Then
            $this->assertSame(ShoppingListException::MEAL_LIMIT_REACHED, $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        }
        $this->assertSame(500, $list->countRecipes());
    }

    public function testAMealIsPlannedForAnInvalidNumberOfPeopleOnlyIfItIsAccepted(): void
    {
        // Given
        $list = $this->list();

        // Then
        $this->expectExceptionObject(MealException::invalidServing());

        // When
        $list->addMeal('meal', $this->recipe(1), 101);
    }

    private function list(): ShoppingList
    {
        return ShoppingList::create('list', self::OWNER, new DateTimeImmutable('2026-10-27 10:00:00'));
    }

    private function recipe(int $number): \Repas\Repas\Domain\Model\Recipe
    {
        return new RecipeBuilder()->withId('recipe-' . $number)->withAuthorId(self::OWNER)->build();
    }
}
