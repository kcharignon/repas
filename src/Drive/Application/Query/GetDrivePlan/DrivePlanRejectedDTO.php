<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use OpenApi\Attributes as OA;

final readonly class DrivePlanRejectedDTO
{
    public function __construct(
        #[OA\Property(description: "Identifiant du produit chez l'enseigne (chiffres)", example: '999000')]
        public string $retailerProductId,
        #[OA\Property(description: 'Libellé du produit', example: 'Farine complète bio 1 kg')]
        public string $label,
    ) {
    }
}
