<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Exception\DomainException;

final class IngredientException extends DomainException
{
    /** Posé par `cannotConvertToUnit()` seulement : la commande vocale en tire une phrase claire (le message, lui, ne se lit jamais). */
    private bool $missingConversion = false;

    // Messages des cas de la fusion de deux ingrédients (ADR 0028 décision 9) : le front les distingue, ils ne se renomment pas
    public const string CANNOT_MERGE_INTO_ITSELF = 'CANNOT_MERGE_INTO_ITSELF';
    public const string MERGE_UNIT_MISSING = 'MERGE_UNIT_MISSING';
    public const string INGREDIENT_IN_SENT_LIST = 'INGREDIENT_IN_SENT_LIST';
    public const string INGREDIENT_USED_BY_ANOTHER_USER = 'INGREDIENT_USED_BY_ANOTHER_USER';
    public const string INGREDIENT_MERGE_CONFLICT = 'INGREDIENT_MERGE_CONFLICT';

    // Message du refus de poser le marqueur « de base » (ING-011) : le front le distingue d'un refus d'accès ordinaire, il ne se renomme pas
    public const string INGREDIENT_BASE_FORBIDDEN = 'INGREDIENT_BASE_FORBIDDEN';

    // Message du cas « Pas le même » (ING-006)
    public const string CANNOT_BE_DISTINCT_FROM_ITSELF = 'CANNOT_BE_DISTINCT_FROM_ITSELF';

    public static function notFound(string $slug): static
    {
        return new static(sprintf("Ingredient '%s' not found", $slug), 404);
    }

    public static function subModelNotFound(): static
    {
        return new static("INGREDIENT_SUB_MODEL_NOT_FOUND", 404);
    }

    public static function cannotConvertToUnit(Ingredient $ingredient, Unit $startUnit, Unit $endUnit): static
    {
        $exception = new static(sprintf(
            "No conversion path found from unit '%s' to purchase unit '%s' for '%s' ingredient.",
            $startUnit->getName(),
            $endUnit->getName(),
            $ingredient->getName(),
        ), 422);
        $exception->missingConversion = true;

        return $exception;
    }

    /**
     * Aucune conversion ne mène de l'unité de la recette à l'unité d'achat de l'ingrédient (`cannotConvertToUnit()`).
     */
    public function isMissingConversion(): bool
    {
        return $this->missingConversion;
    }

    public static function cannotBeRemoveExistInRecipe(string $ingredientId): static
    {
        return new static(sprintf("Cannot remove ingredient '%s', exist in recipe", $ingredientId), 409);
    }

    public static function cannotBeRemoveExistInShoppingList(string $ingredientId): static
    {
        return new static(sprintf("Cannot remove ingredient '%s', exist in shopping list", $ingredientId), 409);
    }
    public static function alreadyExists(string $slug): self
    {
        return new self(sprintf("Ingredient '%s' already exists", $slug), 409);
    }

    public static function coefficientRequired(): self
    {
        return new self('A conversion coefficient is required when the cooking and purchase units differ.', 422);
    }

    /**
     * Fusionner un ingrédient dans lui-même n'a pas de sens. Règle métier, `422`.
     */
    public static function cannotMergeIntoItself(): self
    {
        return new self(self::CANNOT_MERGE_INTO_ITSELF, 422);
    }


    /**
     * Une unité des recettes ou des listes de l'ingrédient absorbé ne se convertit pas par l'ingrédient gardé : la fusion s'arrête et
     * rien n'est écrit. Règle métier, `422`.
     */
    public static function mergeUnitMissing(): self
    {
        return new self(self::MERGE_UNIT_MISSING, 422);
    }

    /**
     * Une liste envoyée au drive utilise l'un des deux ingrédients : elle est immuable (ADR 0023), il faut la rouvrir ou la terminer.
     * Conflit avec l'état courant, `409`.
     */
    public static function inSentList(): self
    {
        return new self(self::INGREDIENT_IN_SENT_LIST, 409);
    }

    /**
     * Une ligne d'un autre utilisateur référence l'ingrédient absorbé (donnée héritée d'avant l'ADR 0021) : la fusion ne la touche pas
     * et s'arrête. Conflit avec l'état courant, `409`.
     */
    public static function usedByAnotherUser(): self
    {
        return new self(self::INGREDIENT_USED_BY_ANOTHER_USER, 409);
    }

    /**
     * Quelqu'un a écrit une référence à l'ingrédient absorbé pendant la fusion (une recette, une ligne de liste) : rien n'est écrit, la
     * fusion se rejoue. Conflit avec l'état courant, `409`.
     */
    public static function mergeConflict(): self
    {
        return new self(self::INGREDIENT_MERGE_CONFLICT, 409);
    }

    /**
     * Seul le propriétaire du catalogue pose le marqueur « de base », sur un ingrédient qui est à lui (amendement du 2026-10-20 de
     * l'ADR 0028, D2). Un refus d'autorisation : `403`, jamais ignoré en silence.
     */
    public static function baseMarkerForbidden(): self
    {
        return new self(self::INGREDIENT_BASE_FORBIDDEN, 403);
    }

    /**
     * Dire qu'un ingrédient n'est pas le même que lui-même n'a pas de sens. Règle métier, `422`.
     */
    public static function cannotBeDistinctFromItself(): self
    {
        return new self(self::CANNOT_BE_DISTINCT_FROM_ITSELF, 422);
    }
}
