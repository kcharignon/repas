<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class ConversionException extends DomainException
{
    public static function notFound(string $id): static
    {
        return new static(sprintf('Conversion "%s" not found.', $id));
    }
}
