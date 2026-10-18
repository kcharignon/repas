<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;

/**
 * L'estimation du coût d'une liste d'après les prix mémorisés : une estimation datée, jamais un prix garanti.
 */
final readonly class CostEstimate
{
    /**
     * @param list<EstimatedLine> $lines dans l'ordre des lignes données
     */
    public function __construct(
        /** Somme des sous-totaux des lignes `ESTIMATED`. */
        public int $totalCents,
        /** Lignes `ESTIMATED`. */
        public int $estimatedLines,
        /** Lignes à acheter qui ne sont pas estimées : `NO_PRODUCT`, `NO_PRICE` et `TO_CHECK`. */
        public int $unestimatedLines,
        /** Lignes estimées sur lignes à acheter (hors « j'en ai toujours »), de 0 à 1 ; 0 quand rien n'est à acheter. */
        public float $coverage,
        /** Date du prix le plus ancien et du plus récent parmi les lignes du total ; `null` sans ligne estimée. */
        public ?DateTimeImmutable $oldestPriceAt,
        public ?DateTimeImmutable $newestPriceAt,
        public array $lines,
    ) {
    }
}
