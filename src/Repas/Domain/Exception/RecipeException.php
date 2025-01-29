<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class RecipeException extends DomainException
{
    public static function notFound(): static
    {
        return new static("RECIPE_NOT_FOUND", 404);
    }

    public static function typeNotFound(): static
    {
        return new static("RECIPE_TYPE_NOT_FOUND", 404);
    }

    public static function rowSubModelNotFound(): static
    {
        return new static("RECIPE_ROW_SUB_MODEL_NOT_FOUND", 404);
    }
}
