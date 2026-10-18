<?php

namespace Repas\Drive\Domain\Model;


/**
 * Un produit accepté pour un ingrédient, avec son rang (1 = le préféré, les suivants servent de secours).
 */
final readonly class AcceptedProduct
{
    public function __construct(
        public string $productId,
        public int $rank,
    ) {
    }
}
