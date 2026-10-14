<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class MealException extends DomainException
{
    public static function notFound(): static
    {
        return new static("MEAL_NOT_FOUND", 404);
    }
}
