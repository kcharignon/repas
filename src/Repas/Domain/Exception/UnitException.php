<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class UnitException extends DomainException
{
    public const string MEASURE_INCOMPLETE = 'UNIT_MEASURE_INCOMPLETE';
    public const string BASE_FACTOR_INVALID = 'UNIT_BASE_FACTOR_INVALID';

    public static function notFound(string $slug): static
    {
        return new static(sprintf("Unit %s not found.", $slug), 404);
    }

    public static function alreadyExists(string $slug): self
    {
        return new self(sprintf("Unit %s already exists.", $slug), 409);
    }

    /**
     * La dimension et le facteur de base vont ensemble : les deux renseignés ou les deux absents. Règle métier, `422`.
     */
    public static function incompleteMeasure(): self
    {
        return new self(self::MEASURE_INCOMPLETE, 422);
    }

    /**
     * Le facteur de base est un nombre strictement positif (combien d'unités de base vaut une unité). Règle métier, `422`.
     */
    public static function invalidBaseFactor(): self
    {
        return new self(self::BASE_FACTOR_INVALID, 422);
    }
}
