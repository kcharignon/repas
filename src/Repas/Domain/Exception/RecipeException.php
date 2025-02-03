<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class RecipeException extends DomainException
{
    public static function notFound(string $id): static
    {
        return new static(sprintf('Recipe id %s not found', $id), 404);
    }

    public static function typeNotFound(): static
    {
        return new static("RECIPE_TYPE_NOT_FOUND", 404);
    }

    public static function rowSubModelNotFound(string $recipe): static
    {
        return new static(sprintf("Can't load recipe '%s'", $recipe), 404);
    }
}
