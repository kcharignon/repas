<?php

namespace Repas\Repas\Domain\Model;


/**
 * Le facteur qui convertit une quantité de `$fromUnitSlug` en `$toUnitSlug` par les conversions de l'ingrédient gardé (le résultat de
 * `ConversionGraph::coefficient()` pour un `MergeUnitMove` qui change d'unité).
 */
final readonly class UnitFactor
{
    public function __construct(
        public string $fromUnitSlug,
        public string $toUnitSlug,
        public float $factor,
    ) {
    }
}
