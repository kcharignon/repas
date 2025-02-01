<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class UnitException extends DomainException
{
    public static function notFound(string $slug): static
    {
        return new static(sprintf("Unit %s not found.", $slug), 404);
    }
}
