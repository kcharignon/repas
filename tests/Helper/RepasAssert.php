<?php

namespace Repas\Tests\Helper;


use Closure;
use PHPUnit\Framework\Assert;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

class RepasAssert
{
    public static function assertUnit(Unit $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertInstanceOf(Unit::class, $actual);
        Assert::assertEquals($expected->getSlug(), $actual->getSlug(), $message);
        Assert::assertEquals($expected->getName(), $actual->getName(), $message);
        Assert::assertEquals($expected->getSymbol(), $actual->getSymbol(), $message);
    }

    public static function assertDepartment(Department $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(Department::class, $actual);
        Assert::assertEquals($expected->getSlug(), $actual->getSlug());
        Assert::assertEquals($expected->getName(), $actual->getName());
        Assert::assertEquals($expected->getImage(), $actual->getImage());
    }

    public static function assertIngredient(Ingredient $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(Ingredient::class, $actual);
        Assert::assertEquals($expected->getSlug(), $actual->getSlug());
        Assert::assertEquals($expected->getName(), $actual->getName());
        Assert::assertEquals($expected->getSluggedName(), $actual->getSluggedName());
        Assert::assertEquals($expected->getImage(), $actual->getImage(), sprintf("Ingredient %s, have wrong image", $expected->getSlug()));
        if ($expected->getCreator() === null) {
            Assert::assertNull($actual->getCreator());
        } else {
            self::assertUser($expected->getCreator(), $actual->getCreator(), "Ingredient {$expected->getSlug()}, have wrong owner");
        }
        self::assertUnit($expected->getDefaultCookingUnit(), $actual->getDefaultCookingUnit(), sprintf("Ingredient %s, have wrong default cooking unit", $expected->getSlug()));
        self::assertUnit($expected->getDefaultPurchaseUnit(), $actual->getDefaultPurchaseUnit(), sprintf("Ingredient %s, have wrong default purchase unit", $expected->getSlug()));
        self::assertTab(
            $expected->getCompatibleUnits(),
            $actual->getCompatibleUnits(),
            fn(Unit $a, Unit $b) => $a->getSlug() <=> $b->getSlug(),
            fn(Unit $expected, mixed $actual, string $message) => self::assertUnit($expected, $actual, $message),
            sprintf("Ingredient %s, have wrong compatible units", $expected->getSlug())
        );
    }

    public static function assertRecipeRow(RecipeRow $expected, mixed $actual, array $excluded = []): void
    {
        Assert::assertInstanceOf(RecipeRow::class, $actual);
        if (!in_array("id", $excluded, true)) {
            Assert::assertEquals($expected->getId(), $actual->getId());
        }
        Assert::assertEquals($expected->getQuantity(), $actual->getQuantity());
        Assert::assertEquals($expected->getRecipeId(), $actual->getRecipeId());
        self::assertIngredient($expected->getIngredient(), $actual->getIngredient());
        self::assertUnit($expected->getUnit(), $actual->getUnit());
    }

    public static function assertUser(User $expected, mixed $actual, ?string $message = null, array $excluded = []): void
    {
        Assert::assertInstanceOf(User::class, $actual, $message ?? '');
        if (!in_array("id", $excluded, true)) {
            Assert::assertEquals($expected->getId(), $actual->getId(), $message ?? "Users ids are different");
        }
        Assert::assertEquals($expected->getEmail(), $actual->getEmail(), $message ?? "Users emails are different");
        Assert::assertEquals($expected->getPassword(), $actual->getPassword(), $message ?? "Users password are different");
        Assert::assertEquals($expected->getRoles(), $actual->getRoles(), $message ?? "Users roles are different");
        Assert::assertEquals($expected->getDefaultServing(), $actual->getDefaultServing());
        Assert::assertEquals($expected->getStatistics(), $actual->getStatistics());
    }

    public static function assertRecipe(Recipe $expected, mixed $actual, array $excluded = []): void
    {
        Assert::assertInstanceOf(Recipe::class, $actual);
        if (!in_array("id", $excluded, true)) {
            Assert::assertEquals($expected->getId(), $actual->getId());
        }
        Assert::assertEquals($expected->getName(), $actual->getName());
        Assert::assertEquals($expected->getServing(), $actual->getServing());
        self::assertRecipeType($expected->getType(), $actual->getType());
        self::assertUser($expected->getAuthor(), $actual->getAuthor());
        self::assertRecipeRows($expected->getRows(), $actual->getRows(), $excluded['RecipeRow'] ?? []);
    }

    public static function assertRecipeType(RecipeType $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(RecipeType::class, $actual);
        Assert::assertEquals($expected->getSlug(), $actual->getSlug());
        Assert::assertEquals($expected->getName(), $actual->getName());
        Assert::assertEquals($expected->getImage(), $actual->getImage());
    }

    public static function assertTabType(Tab $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertInstanceOf(Tab::class, $actual, $message);
        Assert::assertEquals($expected->getType(), $actual->getType(), $message);
    }


    /**
     * @param Tab<RecipeRow> $expected
     */
    public static function assertRecipeRows(Tab $expected, mixed $actual, array $excluded = []): void
    {
        self::assertTab(
            $expected,
            $actual,
            fn(RecipeRow $a, RecipeRow $b) => $a->getIngredient()->getId() <=> $b->getIngredient()->getId(),
            fn(RecipeRow $a, mixed $b) => self::assertRecipeRow($a, $b, $excluded),
        );
    }

    public static function assertMeal(Meal $expected, mixed $actual, array $excluded = []): void
    {
        Assert::assertInstanceOf(Meal::class, $actual);
        if (!in_array("id", $excluded, true)) {
            Assert::assertEquals($expected->getId(), $actual->getId());
        }
        self::assertRecipe($expected->getRecipe(), $actual->getRecipe());
        Assert::assertEquals($expected->getServing(), $actual->getServing());
        Assert::assertEquals($expected->getShoppingListId(), $actual->getShoppingListId());
    }

    /**
     * @param Tab<Meal> $expected
     */
    public static function assertMeals(Tab $expected, mixed $actual): void
    {
        self::assertTab(
            $expected,
            $actual,
            fn($a, $b) => $a->getId() <=> $b->getId(),
            fn($a, $b) => self::assertMeal($a, $b),
        );
    }

    /**
     * @param Tab<Meal> $expected
     */
    public static function assertTab(
        Tab $expected,
        mixed $actual,
        Closure $sortCallback,
        Closure $assertCallback,
        string $message = '',
    ): void {
        // Comparaison type
        self::assertTabType($expected, $actual, $message);

        // Comparaison taille
        Assert::assertCount($expected->count(), $actual, $message);

        // Tri des tableaux pour comparer element par element
        $expected->usort($sortCallback);
        $actual->usort($sortCallback);

        // Comparaison par element
        for ($i = 0; $expected->count() > $i; ++$i) {
            $assertCallback($expected[$i], $actual[$i], $message);
        }
    }

    public static function assertShoppingList(ShoppingList $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(ShoppingList::class, $actual);
        Assert::assertEquals($expected->getId(), $actual->getId());
        Assert::assertEquals($expected->getCreatedAt()->format(DATE_ATOM), $actual->getCreatedAt()->format(DATE_ATOM));
        Assert::assertEquals($expected->getStatus(), $actual->getStatus());
        Assert::assertEquals($expected->getName(), $actual->getName());
        self::assertUser($expected->getOwner(), $actual->getOwner());
        self::assertMeals($expected->getMeals(), $actual->getMeals());
        self::assertShoppingListIngredients($expected->getIngredients(), $actual->getIngredients());
        self::assertShoppingListRows($expected->getRows(), $actual->getRows());
    }

    public static function assertShoppingListRows(Tab $expected, mixed $actual): void
    {
        self::assertTab(
            $expected,
            $actual,
            fn($a, $b) => $a->getId() <=> $b->getId(),
            fn($a, $b) => self::assertShoppingListRow($a, $b)
        );
    }

    public static function assertShoppingListIngredients(Tab $expected, mixed $actual): void
    {
        self::assertTab(
            $expected,
            $actual,
            fn($a, $b) => $a->getId() <=> $b->getId(),
            fn($a, $b) => self::assertShoppingListIngredient($a, $b),
        );
    }

    public static function assertShoppingListIngredient(ShoppingListIngredient $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(ShoppingListIngredient::class, $actual);
        Assert::assertEquals($expected->getId(), $actual->getId());
        Assert::assertEquals($expected->getShoppingListId(), $actual->getShoppingListId());
        Assert::assertEquals($expected->getQuantity(), $actual->getQuantity());
        self::assertUnit($expected->getUnit(), $actual->getUnit());
        self::assertIngredient($expected->getIngredient(), $actual->getIngredient());
    }

    public static function assertConversion(Conversion $expected, mixed $actual, array $excluded = []): void
    {
        Assert::assertInstanceOf(Conversion::class, $actual);
        if (!in_array('id', $excluded, true)) {
            Assert::assertEquals($expected->getId(), $actual->getId());
        }
        Assert::assertEquals($expected->getCoefficient(), $actual->getCoefficient());
        self::assertUnit($expected->getStartUnit(), $actual->getStartUnit());
        self::assertUnit($expected->getEndUnit(), $actual->getEndUnit());
        if (!in_array('ingredient', $excluded)) {
            if ($expected->getIngredient() === null) {
                Assert::assertNull($actual->getIngredient());
            } else {
                self::assertIngredient($expected->getIngredient(), $actual->getIngredient());
            }
        }
    }

    public static function assertShoppingListRow(ShoppingListRow $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(ShoppingListRow::class, $actual);
        Assert::assertEquals($expected->getId(), $actual->getId());
        Assert::assertEquals($expected->isChecked(), $actual->isChecked());
        Assert::assertEquals($expected->getShoppingListId(), $actual->getShoppingListId());
        Assert::assertEquals($expected->getQuantity(), $actual->getQuantity());
        self::assertUnit($expected->getUnit(), $actual->getUnit());
        self::assertIngredient($expected->getIngredient(), $actual->getIngredient());
    }
}
