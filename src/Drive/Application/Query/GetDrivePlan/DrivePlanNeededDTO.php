<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use OpenApi\Attributes as OA;

final readonly class DrivePlanNeededDTO
{
    public function __construct(
        #[OA\Property(description: "Quantité demandée par la liste, dans l'unité d'achat de la ligne", example: 300)]
        public float $quantity,
        #[OA\Property(description: "Symbole de l'unité d'achat (vide pour une unité sans symbole)", example: 'g')]
        public string $unitSymbol,
        #[OA\Property(description: "Dimension de l'unité, `null` si elle ne se convertit pas (cuillère, tranche…)", enum: ['WEIGHT', 'VOLUME', 'COUNT', 'PACKAGE'], nullable: true, example: 'WEIGHT')]
        public ?string $dimension,
        #[OA\Property(description: "Facteur qui ramène l'unité à l'unité de base de sa dimension (1 pour le gramme, 1000 pour le kilo), `null` sans dimension", nullable: true, example: 1)]
        public ?float $baseFactor,
    ) {
    }
}
