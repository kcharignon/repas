<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Une enseigne connue du back, vue de l'utilisateur : son nom, et le magasin qu'il y a enregistré s'il en a un.
 */
final readonly class DriveRetailerDTO
{
    public function __construct(
        #[OA\Property(description: "Identifiant de l'enseigne", enum: Retailer::IDS, example: 'leclerc')]
        public string $retailer,
        #[OA\Property(description: "Nom de l'enseigne, tel que les proches le disent", example: 'Leclerc')]
        public string $name,
        #[OA\Property(description: "Vrai si l'utilisateur a enregistré un magasin chez cette enseigne", example: true)]
        public bool $configured,
        #[OA\Property(description: "Magasin de l'utilisateur chez cette enseigne, `null` tant qu'il n'en a pas enregistré", nullable: true)]
        public ?DriveStoreDTO $store,
    ) {
    }
}
