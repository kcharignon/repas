<?php

namespace Repas\Repas\Application\Query\ListUnits;

use OpenApi\Attributes as OA;

final readonly class UnitDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'unité (slug)', example: 'gramme')]
        public string $slug,
        #[OA\Property(example: 'gramme')]
        public string $name,
        #[OA\Property(description: 'Symbole affiché à côté d\'une quantité (peut être vide)', example: 'g')]
        public string $symbol,
        #[OA\Property(description: 'Ce que l\'unité mesure : poids, volume, pièces ou conditionnement ; null si inconnu (cuillère, tranche…)', enum: ['WEIGHT', 'VOLUME', 'COUNT', 'PACKAGE'], example: 'WEIGHT', nullable: true)]
        public ?string $dimension,
        #[OA\Property(description: 'Combien d\'unités de base (g, ml, pièce) vaut l\'unité, null si la dimension est inconnue', example: 1000, nullable: true)]
        public ?float $baseFactor,
    ) {
    }
}
