<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use OpenApi\Attributes as OA;

final readonly class DriveSettingsDTO
{
    public function __construct(
        #[OA\Property(description: "Vrai si la fonction « envoyer au drive » est allumée (interrupteur `DRIVE_ENABLED`) ; faux, toutes les autres routes du drive répondent `404 DRIVE_DISABLED`", example: true)]
        public bool $enabled,
        #[OA\Property(description: 'Enseigne du drive', enum: ['leclerc'], example: 'leclerc')]
        public string $retailer,
        #[OA\Property(description: "Magasin de l'utilisateur, `null` tant qu'il n'a pas été enregistré", nullable: true)]
        public ?DriveStoreDTO $store,
    ) {
    }
}
