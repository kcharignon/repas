<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;

/**
 * Le résultat du calcul pour une ligne. `subtotalCents` n'est renseigné que pour une ligne `ESTIMATED` ; `packs` seulement
 * quand `PackCalculator` est sûr de son résultat ; le prix et sa date dès qu'un prix est mémorisé.
 */
final readonly class EstimatedLine
{
    public function __construct(
        public string $ingredientSlug,
        public string $ingredientName,
        public EstimateLineStatus $status,
        public ?string $productLabel,
        public ?int $packs,
        public ?int $unitPriceCents,
        public ?int $subtotalCents,
        public ?DateTimeImmutable $priceSeenAt,
    ) {
    }
}
