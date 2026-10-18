<?php

namespace Repas\Drive\Domain\Model;


use Repas\Drive\Domain\Exception\DriveException;

/**
 * Prix d'un produit tel que l'agent l'a lu chez l'enseigne (décision D22) : prix unitaire TTC en centimes, et prix à l'unité de
 * mesure quand le site le donne. Une estimation datée (voir `Product::getPriceSeenAt()`), jamais un prix garanti.
 */
final readonly class ProductPrice
{
    /** Prix plausible : 10 000 € au plus (un produit de supermarché n'approche pas ce montant). */
    public const int MAX_CENTS = 1_000_000;

    /**
     * @throws DriveException `invalidChoice()` si le prix n'est pas plausible (négatif ou au-delà de `MAX_CENTS`)
     */
    public function __construct(
        public int $cents,
        public ?PricePerMeasure $perMeasure = null,
    ) {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw DriveException::invalidChoice();
        }
    }
}
