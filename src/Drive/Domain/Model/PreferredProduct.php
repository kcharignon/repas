<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;

/**
 * Le produit préféré (rang 1) d'un ingrédient dans le magasin courant, tel que la mémoire de Repas le connaît : de quoi
 * estimer, rien de plus. La contenance dans l'unité de la liste (DRV-652) est celle que la personne a donnée pour ce produit.
 */
final readonly class PreferredProduct
{
    public function __construct(
        public string $label,
        public ?ProductContent $content,
        public ?ProductPrice $price,
        public ?DateTimeImmutable $priceSeenAt,
        public ?PackSize $packSize = null,
    ) {
    }
}
