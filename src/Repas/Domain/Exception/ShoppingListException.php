<?php

namespace Repas\Repas\Domain\Exception;


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

    public static function cantAddRecipeInLockedList(string $shoppingList): static
    {
        return new self(sprintf("Forbidden to add recipe in locked shopping list (%s).", $shoppingList), 403);
    }

    public static function activeShoppingListNotFound(): static
    {
        return new self("No active shopping list found.", 403);
    }

    public static function cantRemoveRecipeInLockedList(string $shoppingList): static
    {
        return new self(sprintf("Forbidden to remove recipe in locked shopping list (%s).", $shoppingList), 403);
    }
}
