<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\MealBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;

/**
 * Ce qu'un repas se souvient d'avoir posé dans sa liste (ADR 0036 décision 5, LST-005) et les refus d'un repas de plus, jugés sans rien écrire.
 */
class MealPlacedIngredientsTest extends TestCase
{
    public function testAMealPlacesTheWholeRecipeUnlessItRemembersWhichProductsItPlaced(): void
    {
        // Given
        $whole = Meal::create('meal-1', 'list-1', 'recipe-1', 4);
        $partial = Meal::create('meal-2', 'list-2', 'recipe-1', 4, 'group-1', ['lardon', 'parmesan']);

        // Then
        $this->assertNull($whole->getPlacedIngredients());
        $this->assertTrue($whole->places('anything'));
        $this->assertSame(['lardon', 'parmesan'], $partial->getPlacedIngredients());
        $this->assertTrue($partial->places('lardon'));
        $this->assertFalse($partial->places('pate'));
        $this->assertSame('group-1', $partial->getPlannedGroupId());
    }

    public function testThePlacedProductsAreAListWithoutDuplicates(): void
    {
        // Given / When
        $meal = Meal::create('meal-1', 'list-1', 'recipe-1', 4, 'group-1', ['lardon', 'oeuf', 'lardon']);

        // Then
        $this->assertSame(['lardon', 'oeuf'], $meal->getPlacedIngredients());
    }

    public function testAnEmptyPlacementMeansTheMealPlacedNothing(): void
    {
        // Given : un repas dont la répartition est vide (tous ses produits ont disparu par une fusion) n'est PAS « toute la recette »
        $meal = Meal::create('meal-1', 'list-1', 'recipe-1', 4, 'group-1', []);

        // Then
        $this->assertSame([], $meal->getPlacedIngredients());
        $this->assertFalse($meal->places('lardon'));
    }

    public function testAMealIsLoadedWithItsPlacement(): void
    {
        // Given
        $recipe = new RecipeBuilder()->isPastaCarbonara();
        $meal = Meal::load(['id' => 'm', 'shopping_list_id' => 'l', 'recipe_id' => 'r', 'serving' => 4, 'planned_group_id' => 'g', 'placed_ingredients' => ['lardon']]);
        $old = new MealBuilder()->withShoppingListId('l')->withRecipe($recipe)->build();

        // Then : sans la clé (une ancienne ligne), toute la recette
        $this->assertSame(['g', ['lardon']], [$meal->getPlannedGroupId(), $meal->getPlacedIngredients()]);
        $this->assertNull($old->getPlacedIngredients());
    }

    public function testAMealStillRefusesAnInvalidNumberOfPeople(): void
    {
        $this->expectExceptionObject(MealException::invalidServing());

        Meal::create('meal-1', 'list-1', 'recipe-1', 0, 'group-1', ['lardon']);
    }

    public function testTheRefusalsOfAnExtraMealAreJudgedWithoutWritingAnything(): void
    {
        // Given
        $owner = new UserBuilder()->withId('owner-id')->build();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($owner)->build();
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        $sent = new ShoppingListBuilder()->withOwner($owner)->addIngredient(new IngredientBuilder()->isEgg())->withStatus(ShoppingListStatus::SENT)->build();
        $foreign = new RecipeBuilder()->isPastaCarbonara()->withAuthor(new UserBuilder()->withId('someone-else')->build())->build();

        // When / Then : accepté
        $list->assertCanAddMeal($recipe);
        $this->assertCount(0, $list->getMeals());

        // And : la recette d'un autre est inconnue, la liste envoyée n'est pas modifiable
        $this->assertRefused(fn() => $list->assertCanAddMeal($foreign), RecipeException::notFound($foreign->getId()));
        $this->assertRefused(fn() => $sent->assertCanAddMeal($recipe), ShoppingListException::sent($sent->getId()));

        // And : une recette déjà planifiée ne l'est pas deux fois
        $list->addMeal('meal-1', $recipe, 4, 'group-1', ['lardon']);
        $this->assertRefused(fn() => $list->assertCanAddMeal($recipe), ShoppingListException::recipeAlreadyInList($recipe->getName()));
        $this->assertRefused(fn() => $list->addMeal('meal-2', $recipe, 4), ShoppingListException::recipeAlreadyInList($recipe->getName()));
        $this->assertCount(1, $list->getMeals());
        $this->assertSame(['group-1', ['lardon']], [$list->mealOf($recipe)->getPlannedGroupId(), $list->mealOf($recipe)->getPlacedIngredients()]);
    }

    private function assertRefused(callable $action, \Throwable $expected): void
    {
        try {
            $action();
            $this->fail('Le repas devait être refusé.');
        } catch (\Throwable $exception) {
            $this->assertEquals($expected, $exception);
        }
    }
}
