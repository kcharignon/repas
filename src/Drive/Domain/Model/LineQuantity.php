<?php

namespace Repas\Drive\Domain\Model;


use Repas\Shared\Domain\Model\Dimension;

/**
 * Ce que demande une ligne de liste de courses : une quantité dans l'unité d'achat de la ligne, avec la dimension et le
 * facteur de base de cette unité (nuls pour une unité qui ne se convertit pas : cuillère, tranche…).
 */
final readonly class LineQuantity
{
    public function __construct(
        public float $quantity,
        public ?Dimension $dimension,
        public ?float $baseFactor,
    ) {
    }
}
