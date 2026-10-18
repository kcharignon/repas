<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;

/**
 * Le produit préféré (rang 1) d'un ingrédient dans le magasin courant, tel que la mémoire de Repas le connaît : de quoi
 * estimer, rien de plus.
 */
final readonly class PreferredProduct
{
    public function __construct(
        public string $label,
        public ?ProductContent $content,
        public ?ProductPrice $price,
        public ?DateTimeImmutable $priceSeenAt,
    ) {
    }
}
