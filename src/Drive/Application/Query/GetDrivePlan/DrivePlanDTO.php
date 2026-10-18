<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;

final readonly class DrivePlanDTO
{
    /**
     * @param list<DrivePlanLineDTO> $lines
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de la liste de courses', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $shoppingListId,
        #[OA\Property(description: 'Version de la liste au moment du plan', example: 5)]
        public int $listVersion,
        #[OA\Property(description: 'Enseigne du drive', enum: ['leclerc'], example: 'leclerc')]
        public string $retailer,
        public DriveStoreDTO $store,
        #[OA\Property(description: 'Date de calcul du plan (ISO 8601)', example: '2026-10-18T10:00:00+02:00')]
        public string $generatedAt,
        public DrivePlanCountsDTO $counts,
        #[OA\Property(description: "Une ligne par ingrédient de la liste, dans l'ordre de la liste (slug d'ingrédient croissant)", type: 'array', items: new OA\Items(ref: new Model(type: DrivePlanLineDTO::class)))]
        public array $lines,
    ) {
    }
}
