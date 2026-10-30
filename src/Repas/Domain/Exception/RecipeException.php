<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class RecipeException extends DomainException
{
    // Messages des bornes d'une recette : le front les distingue, ils ne se renomment pas
    public const string QUANTITY_INVALID = 'RECIPE_QUANTITY_INVALID';
    public const string SERVING_INVALID = 'RECIPE_SERVING_INVALID';
    public const string ROWS_LIMIT_REACHED = 'RECIPE_ROWS_LIMIT_REACHED';
    public const string LIMIT_REACHED = 'RECIPE_LIMIT_REACHED';
    public const string SECRET_FORBIDDEN = 'RECIPE_SECRET_FORBIDDEN';
    public const string TYPE_TRANSFER_TO_ITSELF = 'RECIPE_TYPE_TRANSFER_TO_ITSELF';

    public static function notFound(string $id): static
    {
        return new static(sprintf('Recipe id %s not found', $id), 404);
    }

    public static function typeNotFound(string $type): static
    {
        return new static(sprintf("Recipe type '%s' not found", $type), 404);
    }

    public static function rowSubModelNotFound(string $recipe): static
    {
        return new static(sprintf("Can't load recipe '%s'", $recipe), 404);
    }

    public static function cannotRemoveExistInShoppingList(string $recipeId): static
    {
        return new static(sprintf("Cannot remove recipe '%s', present in shopping list", $recipeId), 409);
    }

    /**
     * Le client a lu une autre version que celle d'aujourd'hui (`If-Match`).
     */
    public static function outdated(string $id): static
    {
        return new static(sprintf("Recipe '%s' was modified since it was read", $id), 412);
    }

    /**
     * Quelqu'un d'autre a enregistré la recette entre la lecture et l'écriture de cette requête.
     */
    public static function concurrentModification(string $id): static
    {
        return new static(sprintf("Recipe '%s' was modified by someone else, try again", $id), 409);
    }

    public static function typeAlreadyExists(string $slug): self
    {
        return new self(sprintf("Recipe type '%s' already exists", $slug), 409);
    }

    /**
     * La quantité d'une ligne est un nombre fini, strictement positif, d'un million au plus (`QuantityBounds`). Règle métier, `422`.
     */
    public static function invalidQuantity(): self
    {
        return new self(self::QUANTITY_INVALID, 422);
    }

    /**
     * Le nombre de personnes d'une recette va de 1 à 100. Règle métier, `422`.
     */
    public static function invalidServing(): self
    {
        return new self(self::SERVING_INVALID, 422);
    }

    /**
     * Une recette n'a pas plus de lignes que `Recipe::MAX_ROWS`. Règle métier, `422`.
     */
    public static function tooManyRows(): self
    {
        return new self(self::ROWS_LIMIT_REACHED, 422);
    }

    /**
     * L'auteur a déjà toutes les recettes que l'application lui permet d'écrire (`Recipe::MAX_PER_AUTHOR`), ou d'avoir en comptant ses
     * copies (`Recipe::MAX_PER_AUTHOR_WITH_COPIES`). Règle métier, `422`.
     */
    public static function limitReached(): self
    {
        return new self(self::LIMIT_REACHED, 422);
    }

    /**
     * Seul l'auteur d'une recette la rend secrète ou de nouveau visible (ADR 0033) : un autre utilisateur, un administrateur compris, qui
     * envoie une autre valeur que l'actuelle reçoit un refus d'autorisation, `403`, jamais ignoré en silence.
     */
    public static function secretForbidden(): self
    {
        return new self(self::SECRET_FORBIDDEN, 403);
    }

    /**
     * Les recettes d'un type supprimé passent dans un autre type : le type de transfert ne peut pas être le type supprimé lui-même. `422`.
     */
    public static function typeTransferToItself(): self
    {
        return new self(self::TYPE_TRANSFER_TO_ITSELF, 422);
    }
}
