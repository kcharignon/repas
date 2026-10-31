<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\MealBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;

/**
 * Les trois colonnes facultatives que les types de liste ajoutent aux modèles (ADR 0036, LST-002) : le choix de liste d'un produit, le type d'une
 * liste et le groupe de planification d'un repas. Vides par défaut (« zéro type = rien ne change »), lues par `load()`, jamais copiées avec un produit.
 */
class ListTypeColumnsTest extends TestCase
{
    public function testAnIngredientHasNoListDestinationUnlessLoadedWithOne(): void
    {
        // Then
        $this->assertNull(new IngredientBuilder()->isButter()->build()->getListDestination());
        $this->assertSame('type-1', new IngredientBuilder()->isButter()->withListDestination('type-1')->build()->getListDestination());
        $this->assertSame('main', new IngredientBuilder()->isButter()->withListDestination('main')->build()->getListDestination());
    }

    public function testUpdatingAnIngredientKeepsItsListDestinationAndItCanBeCleared(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isButter()->withListDestination('type-1')->build();

        // When
        $ingredient->update('Beurre demi-sel', $ingredient->getImage(), $ingredient->getDepartment(), $ingredient->getDefaultCookingUnit(), $ingredient->getDefaultPurchaseUnit(), $ingredient->getCompatibleUnits());

        // Then
        $this->assertSame('type-1', $ingredient->getListDestination());
        $this->assertNull($ingredient->changeListDestination(null)->getListDestination());
    }

    public function testTheCopyOfAnIngredientNeverCarriesTheListDestinationOfTheOriginal(): void
    {
        // Given : le choix d'un utilisateur ne suit pas la copie (copie de recette, amorçage d'un autre compte)
        $original = new IngredientBuilder()->isButter()->withListDestination('type-1')->build();

        // When
        $copy = Ingredient::copyFromOriginal($original, 'another-user', 'beurre-another-user');

        // Then
        $this->assertNull($copy->getListDestination());
        $this->assertSame('type-1', $original->getListDestination());
    }

    public function testAShoppingListIsTheMainListUnlessLoadedWithAType(): void
    {
        // Then
        $this->assertNull(new ShoppingListBuilder()->build()->getListTypeId());
        $this->assertSame('type-1', new ShoppingListBuilder()->withListTypeId('type-1')->build()->getListTypeId());
        $this->assertNull(ShoppingList::create('list-1', 'user-1', new \DateTimeImmutable())->getListTypeId());
    }

    public function testDetachingAnActiveTypedListPausesItAndKeepsEverythingElse(): void
    {
        // Given
        $owner = new UserBuilder()->withId('owner-id')->build();
        $recipe = new RecipeBuilder()->withAuthor($owner)->isPastaCarbonara()->build();
        $list = new ShoppingListBuilder()->withOwner($owner)->withListTypeId('type-1')->addRecipe($recipe)->addIngredient(new IngredientBuilder()->isEgg())->build();
        $meals = $list->countRecipes();
        $rows = $list->countRows();

        // When
        $list->detachFromListType();

        // Then
        $this->assertNull($list->getListTypeId());
        $this->assertSame(Status::PAUSED, $list->getStatus());
        $this->assertSame([$meals, $rows], [$list->countRecipes(), $list->countRows()]);
        $this->assertGreaterThan(0, $meals + $rows);
    }

    public function testDetachingKeepsTheStatusOfAListThatIsNotCurrent(): void
    {
        // Given
        $sent = new ShoppingListBuilder()->withListTypeId('type-1')->withStatus(Status::SENT)->build();
        $paused = new ShoppingListBuilder()->withListTypeId('type-1')->withStatus(Status::PAUSED)->build();
        $completed = new ShoppingListBuilder()->withListTypeId('type-1')->withStatus(Status::COMPLETED)->build();

        // When
        foreach ([$sent, $paused, $completed] as $list) {
            $list->detachFromListType();
        }

        // Then : une liste envoyée au drive reste envoyée, on n'y touche pas
        $this->assertSame([Status::SENT, Status::PAUSED, Status::COMPLETED], [$sent->getStatus(), $paused->getStatus(), $completed->getStatus()]);
        $this->assertSame([null, null, null], [$sent->getListTypeId(), $paused->getListTypeId(), $completed->getListTypeId()]);
    }

    public function testAMealIsPlannedAloneUnlessLoadedWithAGroup(): void
    {
        // Then
        $recipe = new RecipeBuilder()->isPastaCarbonara();
        $this->assertNull(new MealBuilder()->withShoppingListId('list-1')->withRecipe($recipe)->build()->getPlannedGroupId());
        $this->assertSame('group-1', new MealBuilder()->withShoppingListId('list-1')->withRecipe($recipe)->withPlannedGroupId('group-1')->build()->getPlannedGroupId());
        $this->assertNull(Meal::create('meal-1', 'list-1', 'recipe-1', 4)->getPlannedGroupId());
    }
}
