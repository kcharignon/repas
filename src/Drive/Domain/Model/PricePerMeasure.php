<?php

namespace Repas\Drive\Domain\Model;


use Repas\Drive\Domain\Exception\DriveException;

/**
 * Prix TTC à l'unité de mesure (le kilo, le litre ou la pièce), en centimes.
 */
final readonly class PricePerMeasure
{
    /**
     * @throws DriveException `invalidChoice()` si le prix n'est pas plausible (négatif ou au-delà de `ProductPrice::MAX_CENTS`)
     */
    public function __construct(
        public int $cents,
        public PriceUnit $unit,
    ) {
        if ($cents < 0 || $cents > ProductPrice::MAX_CENTS) {
            throw DriveException::invalidChoice();
        }
    }
}
