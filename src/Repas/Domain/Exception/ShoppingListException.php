<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Exception\DomainException;

final class ShoppingListException extends DomainException
{
    // Messages des cas métier de la liste envoyée au drive (ADR 0023) : le front les distingue
    public const string SENT = 'SHOPPING_LIST_SENT';
    public const string NOT_SENDABLE = 'SHOPPING_LIST_NOT_SENDABLE';
    public const string NOT_SENT = 'SHOPPING_LIST_NOT_SENT';
    public const string EMPTY = 'SHOPPING_LIST_EMPTY';
    // Le drive est coupé (`DRIVE_ENABLED`) : le même code que les routes de `Drive`, le front le connaît
    public const string DRIVE_DISABLED = 'DRIVE_DISABLED';
    // Messages des bornes d'une liste : le front les distingue, ils ne se renomment pas
    public const string QUANTITY_INVALID = 'SHOPPING_LIST_QUANTITY_INVALID';
    public const string MEAL_LIMIT_REACHED = 'SHOPPING_LIST_MEAL_LIMIT_REACHED';
    public const string LIMIT_REACHED = 'SHOPPING_LIST_LIMIT_REACHED';

    /**
     * La liste est envoyée au drive : elle n'est plus modifiable, il faut la rouvrir (ADR 0023).
     */
    public static function sent(string $id): static
    {
        return new static(self::SENT, 409);
    }

    /**
     * Le drive est coupé : la route d'envoi fait comme si elle n'existait pas, `404` (docs/drive/architecture.md § 4.5).
     */
    public static function driveDisabled(): static
    {
        return new static(self::DRIVE_DISABLED, 404);
    }

    /**
     * Seule une liste active (ou déjà envoyée) peut être envoyée.
     */
    public static function notSendable(string $id): static
    {
        return new static(self::NOT_SENDABLE, 409);
    }

    public static function notSent(string $id): static
    {
        return new static(self::NOT_SENT, 409);
    }

    /**
     * Une liste sans ligne n'a rien à envoyer. Règle métier, `422`.
     */
    public static function empty(string $id): static
    {
        return new static(self::EMPTY, 422);
    }

    /**
     * Une quantité de ligne est un nombre fini : un calcul qui sortirait des flottants n'est jamais enregistré. Règle métier, `422`.
     */
    public static function invalidQuantity(): static
    {
        return new static(self::QUANTITY_INVALID, 422);
    }

    /**
     * Une liste ne planifie pas plus de repas que `ShoppingList::MAX_MEALS`. Règle métier, `422`.
     */
    public static function mealLimitReached(): static
    {
        return new static(self::MEAL_LIMIT_REACHED, 422);
    }

    /**
     * L'utilisateur a déjà toutes les listes que l'application lui permet de garder. Règle métier, `422`.
     */
    public static function limitReached(): static
    {
        return new static(self::LIMIT_REACHED, 422);
    }

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

    /**
     * Une quantité dite à voix haute hors de 1 à 99 : rien n'est écrit. Règle métier, `422`.
     */
    public static function invalidSpokenQuantity(int $quantity): static
    {
        return new static(sprintf('Invalid spoken quantity (%d), expected 1 to 99.', $quantity), 422);
    }

    /**
     * Un nombre de personnes dit à voix haute hors de 1 à 99 : rien n'est écrit. Règle métier, `422`.
     */
    public static function invalidSpokenServings(int $servings): static
    {
        return new static(sprintf('Invalid spoken servings (%d), expected 1 to 99.', $servings), 422);
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
