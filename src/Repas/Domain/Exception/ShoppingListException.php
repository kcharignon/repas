<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Exception\DomainException;

class ShoppingListException extends DomainException
{

    public static function shoppingListNotFound(): static
    {
        return new self("SHOPPING_LIST_NOT_FOUND", 404);
    }

    public static function recipeAlreadyInList(string $recipe): static
    {
        return new self(sprintf("Recipe (%s) already present in list", $recipe), 403);
    }

    public static function cannotAddRecipeToShoppingListUnlessPlanning(string $shoppingList): static
    {
        return new self(sprintf("Forbidden to add recipe when shopping list is not in planning step (%s).", $shoppingList), 403);
    }

    public static function activeShoppingListNotFound(): static
    {
        return new self("No active shopping list found.", 403);
    }

    public static function cannotRemoveRecipeToShoppingListUnlessPlanning(string $shoppingList): static
    {
        return new self(sprintf("Forbidden to remove recipe when shopping list is not in planning step (%s).", $shoppingList), 403);
    }

    public static function shoppingListShouldBeOnPlanningBeforeShopping(string $id, ShoppingListStatus $status): static
    {
        return new self(sprintf("Shopping list (%s) should be on status PLANNING (actual : %s) before SHOPPING", $id, $status->value), 403);
    }

    public static function shoppingListShouldBeOnShoppingBeforeRevertToPlanning(string $id, ShoppingListStatus $status): static
    {
        return new self(sprintf("Shopping list (%s) should be on status SHOPPING (actual : %s) before revert to PLANNING", $id, $status->value), 403);
    }
}
