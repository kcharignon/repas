<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveDriveSettings;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\Retailer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de l'enregistrement du magasin du drive. Le magasin est lu par l'agent sur le site de l'enseigne, jamais saisi à la
 * main ; l'adresse et l'identifiant sont contrôlés par le domaine (`DRIVE_STORE_INVALID`).
 */
final readonly class DriveSettingsPayload
{
    public function __construct(
        #[OA\Property(description: 'Enseigne du drive', enum: ['leclerc'], example: 'leclerc')]
        public Retailer $retailer,
        #[Assert\Valid]
        public DriveStorePayload $store,
    ) {
    }
}
