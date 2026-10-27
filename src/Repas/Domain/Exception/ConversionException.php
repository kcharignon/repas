<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class ConversionException extends DomainException
{
    /** Le message est une constante : le front le distingue, il ne se renomme pas. */
    public const string UNIVERSAL_CONVERSION_IS_FIXED = 'UNIVERSAL_CONVERSION_IS_FIXED';

    public static function notFound(string $id): static
    {
        return new static(sprintf('Conversion "%s" not found.', $id), 404);
    }

    /**
     * Une conversion universelle (sans ingrédient : « kilo > gramme », « litre > millilitre ») sert à TOUS les ingrédients de tous les
     * comptes : elle ne change ni de paire d'unités ni d'ingrédient (elle ne devient pas propre à l'un d'eux), seul son coefficient se corrige.
     */
    public static function universalIsFixed(): static
    {
        return new static(self::UNIVERSAL_CONVERSION_IS_FIXED, 422);
    }
}
