<?php

namespace Repas\Drive\Domain\Model;


/**
 * Un produit accepté pour un ingrédient, avec son rang (1 = le préféré, les suivants servent de secours) et, quand la personne
 * l'a donnée, sa contenance dans l'unité de la liste (DRV-652) : propre à ce couple (préférence, produit).
 */
final readonly class AcceptedProduct
{
    public function __construct(
        public string $productId,
        public int $rank,
        public ?PackSize $packSize = null,
    ) {
    }
}
