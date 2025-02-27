<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class RecipeException extends DomainException
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

    public static function cannotRemoveExistInShoppingList(): static
    {
        return new static("Cannot remove recipe '%s', present in shopping list", 403);
    }
}
