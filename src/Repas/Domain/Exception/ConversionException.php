<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class ConversionException extends DomainException
{
    /** Le message est une constante : le front le distingue, il ne se renomme pas. */
    public const string UNIVERSAL_CONVERSION_IS_FIXED = 'UNIVERSAL_CONVERSION_IS_FIXED';
    public const string CONVERSION_ALREADY_EXISTS = 'CONVERSION_ALREADY_EXISTS';
    public const string CONVERSION_REQUIRED_BY_INGREDIENT = 'CONVERSION_REQUIRED_BY_INGREDIENT';
    public const string CONVERSION_SAME_UNIT = 'CONVERSION_SAME_UNIT';
    public const string COEFFICIENT_INVALID = 'CONVERSION_COEFFICIENT_INVALID';

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

    /**
     * L'ingrédient a déjà sa propre conversion entre ces deux unités (dans un sens ou dans l'autre) : on modifie celle qui existe, on n'en
     * ajoute pas une seconde qui dirait autre chose.
     */
    public static function alreadyExists(): static
    {
        return new static(self::CONVERSION_ALREADY_EXISTS, 409);
    }

    /**
     * Sans cette conversion (retirée ou modifiée), l'unité des recettes de l'ingrédient ne se convertirait plus en son unité d'achat : la
     * planification de ses recettes échouerait. Règle métier, `422`.
     */
    public static function requiredByIngredient(): static
    {
        return new static(self::CONVERSION_REQUIRED_BY_INGREDIENT, 422);
    }

    /**
     * Le coefficient d'une conversion est un nombre fini de 0,000001 à 1 000 000 (`QuantityBounds`). Règle métier, `422`.
     */
    public static function invalidCoefficient(): static
    {
        return new static(self::COEFFICIENT_INVALID, 422);
    }

    /** Une conversion relie deux unités différentes. */
    public static function sameUnit(): static
    {
        return new static(self::CONVERSION_SAME_UNIT, 422);
    }
}
