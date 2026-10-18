<?php

namespace Repas\Drive\Domain\Model;


/**
 * Une ligne de la liste à estimer : ce qu'il faut acheter, et ce que Repas sait du produit préféré de l'utilisateur.
 */
final readonly class EstimateLineInput
{
    public function __construct(
        public string $ingredientSlug,
        public string $ingredientName,
        public LineQuantity $quantity,
        public bool $haveAtHome,
        public ?PreferredProduct $product,
    ) {
    }
}
