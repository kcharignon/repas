<?php

namespace Repas\Repas\Domain\Model;


/**
 * Les bornes des quantités et des coefficients que l'application accepte : toujours un nombre fini, strictement positif et plausible.
 * Un nombre non fini (`INF`, `NAN`) ne s'encode pas en JSON et fausse tous les calculs qui le touchent ; un nombre démesuré sort des
 * flottants au premier produit. Le modèle de domaine est la garde finale (`RecipeRow`, `Conversion`, `Unit`, `ShoppingListRow`) : les
 * contraintes des corps de requête (`Assert\…`) ne font que répondre plus tôt, avec un message lisible.
 *
 * Les lectures restent tolérantes : `load()` ne contrôle rien, une valeur déjà en base ne casse jamais un affichage.
 */
final readonly class QuantityBounds
{
    /** Quantité d'une ligne de recette dans son unité : un million (un sac de 25 kg compté en grammes en fait 25 000). */
    public const float MAX_QUANTITY = 1_000_000.0;

    /**
     * Coefficient d'une conversion ou facteur de base d'une unité : de un millionième à un million. L'intervalle est symétrique, de sorte
     * que l'inverse d'un coefficient permis (une conversion écrite « à l'envers ») est permis aussi.
     */
    public const float MIN_COEFFICIENT = 0.000001;
    public const float MAX_COEFFICIENT = 1_000_000.0;

    /** Tolérance relative sur les bornes du coefficient : `1 / 0.000001` n'est pas exactement `1000000.0` en virgule flottante. */
    private const float TOLERANCE = 1e-9;

    private function __construct()
    {
    }

    /**
     * Une quantité de recette : finie, strictement positive, d'un million au plus.
     */
    public static function isQuantity(float $value): bool
    {
        return is_finite($value) && $value > 0 && $value <= self::MAX_QUANTITY;
    }

    /**
     * Un coefficient de conversion ou un facteur de base : fini, de 0,000001 à 1 000 000 (bornes comprises, à la tolérance des flottants près).
     */
    public static function isCoefficient(float $value): bool
    {
        return is_finite($value)
            && $value >= self::MIN_COEFFICIENT * (1 - self::TOLERANCE)
            && $value <= self::MAX_COEFFICIENT * (1 + self::TOLERANCE);
    }
}
