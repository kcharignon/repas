<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class MealException extends DomainException
{
    public const string SERVING_INVALID = 'MEAL_SERVING_INVALID';

    public static function notFound(): static
    {
        return new static("MEAL_NOT_FOUND", 404);
    }

    /**
     * Le nombre de personnes d'un repas va de 1 à 100. Règle métier, `422`.
     */
    public static function invalidServing(): static
    {
        return new static(self::SERVING_INVALID, 422);
    }
}
