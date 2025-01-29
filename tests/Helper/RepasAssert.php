<?php

namespace Repas\Tests\Helper;


use PHPUnit\Framework\Assert;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

class RepasAssert
{
    public static function assertUnit(Unit $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(Unit::class, $actual);
        Assert::assertEquals($expected->getSlug(), $actual->getSlug());
        Assert::assertEquals($expected->getName(), $actual->getName());
        Assert::assertEquals($expected->getSymbol(), $actual->getSymbol());
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
        Assert::assertEquals($expected->getImage(), $actual->getImage());
        self::assertUnit($expected->getDefaultCookingUnit(), $actual->getDefaultCookingUnit());
        self::assertUnit($expected->getDefaultPurchaseUnit(), $actual->getDefaultPurchaseUnit());
    }

    public static function assertRecipeRow(RecipeRow $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(RecipeRow::class, $actual);
        Assert::assertEquals($expected->getId(), $actual->getId());
        Assert::assertEquals($expected->getQuantity(), $actual->getQuantity());
        Assert::assertEquals($expected->getRecipeId(), $actual->getRecipeId());
        self::assertIngredient($expected->getIngredient(), $actual->getIngredient());
        self::assertUnit($expected->getUnit(), $actual->getUnit());
    }

    public static function assertUser(User $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(User::class, $actual);
        Assert::assertEquals($expected->getId(), $actual->getId());
        Assert::assertEquals($expected->getEmail(), $actual->getEmail());
        Assert::assertEquals($expected->getPassword(), $actual->getPassword());
        Assert::assertEquals($expected->getRoles(), $actual->getRoles());
    }

    public static function assertRecipe(Recipe $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(Recipe::class, $actual);
        Assert::assertEquals($expected->getId(), $actual->getId());
        Assert::assertEquals($expected->getName(), $actual->getName());
        Assert::assertEquals($expected->getServing(), $actual->getServing());
        self::assertUser($expected->getAuthor(), $actual->getAuthor());
        self::assertRecipeRows($expected->getRows(), $actual->getRows());
    }

    public static function assertTab(Tab $expected, mixed $actual): void
    {
        Assert::assertInstanceOf(Tab::class, $actual);
        Assert::assertEquals($expected->getType(), $actual->getType());
    }

    /**
     * @param Tab<RecipeRow> $expected
     */
    public static function assertRecipeRows(Tab $expected, mixed $actual): void
    {
        self::assertTab(Tab::newEmptyTyped(RecipeRow::class), $actual);
        $expected->usort(fn($a, $b) => $a->getId() <=> $b->getId());
        $actual->usort(fn($a, $b) => $a->getId() <=> $b->getId());
        Assert::assertCount($expected->count(), $actual);
        for ($i = 0; $expected->count() > $i; ++$i) {
            self::assertRecipeRow($expected[$i], $actual[$i]);
        }
    }
}
