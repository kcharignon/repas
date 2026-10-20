<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Shared\Domain\Exception\DomainException;

final class IngredientAliasException extends DomainException
{
    public static function invalidSource(string $source): static
    {
        return new static(
            sprintf("Alias source '%s' must be between 1 and %d characters.", $source, IngredientAlias::MAX_SOURCE_LENGTH),
            422,
        );
    }

    public static function pointsToItself(string $slug): static
    {
        return new static(sprintf("Alias '%s' cannot designate itself.", $slug), 422);
    }
}
