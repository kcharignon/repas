<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class UnitException extends DomainException
{
    public static function notFound(): static
    {
        return new static("UNIT_NOT_FOUND", 404);
    }
}
