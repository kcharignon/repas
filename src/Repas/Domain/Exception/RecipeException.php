<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class RecipeException extends DomainException
{
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
}
