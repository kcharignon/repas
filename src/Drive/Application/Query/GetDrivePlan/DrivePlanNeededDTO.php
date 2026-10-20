<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use OpenApi\Attributes as OA;

final readonly class DrivePlanNeededDTO
{
    public function __construct(
        #[OA\Property(description: "Quantité demandée par la liste, dans l'unité d'achat de la ligne", example: 300)]
        public float $quantity,
        #[OA\Property(description: "Symbole de l'unité d'achat, ou son nom si elle n'a pas de symbole (jamais vide, 32 caractères au plus)", example: 'g')]
        public string $unitSymbol,
        #[OA\Property(description: "Dimension de l'unité, `null` si elle ne se convertit pas (cuillère, tranche…)", enum: ['WEIGHT', 'VOLUME', 'COUNT', 'PACKAGE'], nullable: true, example: 'WEIGHT')]
        public ?string $dimension,
        #[OA\Property(description: "Facteur qui ramène l'unité à l'unité de base de sa dimension (1 pour le gramme, 1000 pour le kilo), `null` sans dimension", nullable: true, example: 1)]
        public ?float $baseFactor,
        #[OA\Property(description: "Slug de l'unité d'achat (DRV-652) : c'est dans cette unité qu'une contenance (`packSize` d'un produit) se compte et se compare. Le même que `unitSlug` d'une ligne de la liste de courses", example: 'tranche')]
        public string $unitSlug,
        #[OA\Property(description: "Nom de l'unité d'achat (DRV-652), au singulier, tel que Repas l'écrit : de quoi demander « Combien de tranches y a-t-il dans ce produit ? » sans deviner le nom depuis son symbole", example: 'tranche')]
        public string $unitName,
    ) {
    }
}
