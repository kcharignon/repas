<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class ConversionException extends DomainException
{
    public static function notFound(string $id): static
    {
        return new static(sprintf('Conversion "%s" not found.', $id), 404);
    }
}
