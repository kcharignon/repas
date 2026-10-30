<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Exception\DomainException;

final class IngredientException extends DomainException
{
    /** Posé par `cannotConvertToUnit()` seulement : la commande vocale en tire une phrase claire (le message, lui, ne se lit jamais). */
    private bool $missingConversion = false;

    /** Posé avec `$missingConversion` : le nom de l'ingrédient et l'unité de départ, que la commande vocale dit à son propriétaire (jamais au journal). */
    private ?string $conversionIngredientName = null;
    private ?string $conversionStartUnitName = null;

    // Messages des cas de la fusion de deux ingrédients (ADR 0028 décision 9) : le front les distingue, ils ne se renomment pas
    public const string CANNOT_MERGE_INTO_ITSELF = 'CANNOT_MERGE_INTO_ITSELF';
    public const string MERGE_UNIT_MISSING = 'MERGE_UNIT_MISSING';
    public const string INGREDIENT_IN_SENT_LIST = 'INGREDIENT_IN_SENT_LIST';
    public const string INGREDIENT_USED_BY_ANOTHER_USER = 'INGREDIENT_USED_BY_ANOTHER_USER';
    public const string INGREDIENT_MERGE_CONFLICT = 'INGREDIENT_MERGE_CONFLICT';

    // Message du refus de poser le marqueur « de base » (ING-011) : le front le distingue d'un refus d'accès ordinaire, il ne se renomme pas
    public const string INGREDIENT_BASE_FORBIDDEN = 'INGREDIENT_BASE_FORBIDDEN';

    // Message du plafond d'ingrédients créés par un compte : le front le distingue, il ne se renomme pas
    public const string LIMIT_REACHED = 'INGREDIENT_LIMIT_REACHED';

    // Message du refus d'un second ingrédient de même nom dans le même rayon chez le même propriétaire (ING-016) : le front le distingue
    public const string NAME_ALREADY_USED = 'INGREDIENT_NAME_ALREADY_USED';

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
        $exception->conversionIngredientName = $ingredient->getName();
        $exception->conversionStartUnitName = $startUnit->getName();

        return $exception;
    }

    /**
     * Aucune conversion ne mène de l'unité de la recette à l'unité d'achat de l'ingrédient (`cannotConvertToUnit()`).
     */
    public function isMissingConversion(): bool
    {
        return $this->missingConversion;
    }

    /**
     * L'ingrédient dont une unité ne se convertit pas (`cannotConvertToUnit()`), `null` pour toute autre erreur.
     */
    public function conversionIngredientName(): ?string
    {
        return $this->conversionIngredientName;
    }

    /**
     * L'unité qui ne mène nulle part (`cannotConvertToUnit()`), `null` pour toute autre erreur.
     */
    public function conversionStartUnitName(): ?string
    {
        return $this->conversionStartUnitName;
    }

    public static function cannotBeRemoveExistInRecipe(string $ingredientId): static
    {
        return new static(sprintf("Cannot remove ingredient '%s', exist in recipe", $ingredientId), 409);
    }

    public static function cannotBeRemoveExistInShoppingList(string $ingredientId): static
    {
        return new static(sprintf("Cannot remove ingredient '%s', exist in shopping list", $ingredientId), 409);
    }

    /**
     * Le propriétaire a déjà un ingrédient de ce nom (une fois normalisé : sans accent ni majuscule) DANS CE RAYON (ADR 0028, amendement du
     * 2026-10-30, ING-016). Le même nom dans un autre rayon est permis (« Petits pois » surgelés et « Petits pois » frais). Valable à la
     * création comme à la modification (nom ou rayon). Conflit avec l'état courant, `409`.
     */
    public static function nameAlreadyUsedInDepartment(): self
    {
        return new self(self::NAME_ALREADY_USED, 409);
    }

    public static function coefficientRequired(): self
    {
        return new self('A conversion coefficient is required when the cooking and purchase units differ.', 422);
    }

    /** Un coefficient donné doit être un nombre fini de 0,000001 à 1 000 000 (le HTTP le valide déjà ; le cas d'usage ne se fie pas à son appelant). */
    public static function invalidCoefficient(): self
    {
        return new self('The conversion coefficient must be a number between 0.000001 and 1000000.', 422);
    }

    /**
     * L'utilisateur a déjà tous les ingrédients que l'application lui permet de créer. Règle métier, `422`. Seule la création en est refusée :
     * la copie d'une recette et l'amorçage d'un compte ne comptent pas.
     */
    public static function limitReached(): self
    {
        return new self(self::LIMIT_REACHED, 422);
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
