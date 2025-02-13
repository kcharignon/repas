<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Exception\DomainException;

class IngredientException extends DomainException
{
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
        return new static(sprintf(
            "No conversion path found from unit '%s' to purchase unit '%s' for '%s' ingredient.",
            $startUnit->getName(),
            $endUnit->getName(),
            $ingredient->getName(),
        ), 403);
    }
}
