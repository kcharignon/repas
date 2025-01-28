<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class IngredientException extends DomainException
{
    public static function notFound(): static
    {
        return new static("INGREDIENT_NOT_FOUND", 404);
    }

    public static function subModelNotFound(): static
    {
        return new static("INGREDIENT_SUB_MODEL_NOT_FOUND", 404);
    }
}
