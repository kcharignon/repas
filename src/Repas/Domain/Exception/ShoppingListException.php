<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Exception\DomainException;

final class ShoppingListException extends DomainException
{

    public static function shoppingListNotFound(string $id): static
    {
        return new static(sprintf("Shopping list '%s' not founded", $id), 404);
    }

    /**
     * Le client a lu une autre version que celle d'aujourd'hui (`If-Match`).
     */
    public static function outdated(string $id): static
    {
        return new static(sprintf("Shopping list '%s' was modified since it was read", $id), 412);
    }

    /**
     * Quelqu'un d'autre a enregistré la liste entre la lecture et l'écriture de cette requête.
     */
    public static function concurrentModification(string $id): static
    {
        return new static(sprintf("Shopping list '%s' was modified by someone else, try again", $id), 409);
    }

    public static function recipeAlreadyInList(string $recipe): static
    {
        return new static(sprintf("Recipe (%s) already present in list", $recipe), 409);
    }

    public static function cannotAddRecipeToShoppingListUnlessPlanning(string $shoppingList): static
    {
        return new static(sprintf("Forbidden to add recipe when shopping list is not in planning step (%s).", $shoppingList), 403);
    }

    public static function activeShoppingListNotFound(): static
    {
        return new static("No active shopping list found.", 404);
    }

    public static function cannotRemoveRecipeToShoppingListUnlessPlanning(string $shoppingList): static
    {
        return new static(sprintf("Forbidden to remove recipe when shopping list is not in planning step (%s).", $shoppingList), 403);
    }

    public static function shoppingListShouldBeOnPlanningBeforeShopping(string $id, ShoppingListStatus $status): static
    {
        return new static(sprintf("Shopping list (%s) should be on status PLANNING (actual : %s) before SHOPPING", $id, $status->value), 403);
    }

    public static function shoppingListShouldBeOnShoppingBeforeRevertToPlanning(string $id, ShoppingListStatus $status): static
    {
        return new static(sprintf("Shopping list (%s) should be on status SHOPPING (actual : %s) before revert to PLANNING", $id, $status->value), 403);
    }
}
