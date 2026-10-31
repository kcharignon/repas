<?php

namespace Repas\Drive\Domain\Model;



/**
 * Ce que demande une ligne de liste de courses : une quantité dans l'unité d'achat de la ligne, avec la dimension et le
 * facteur de base de cette unité (nuls pour une unité qui ne se convertit pas : cuillère, tranche…) et son slug (DRV-652), qui
 * permet de reconnaître une contenance mémorisée dans cette unité (`PackSize`). Le slug est facultatif : sans lui, aucune
 * contenance ne s'applique.
 */
final readonly class LineQuantity
{
    public function __construct(
        public float $quantity,
        public ?Dimension $dimension,
        public ?float $baseFactor,
        public ?string $unitSlug = null,
    ) {
    }
}
