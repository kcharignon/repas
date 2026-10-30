<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\Retailer;

final readonly class DriveSettingsDTO
{
    /**
     * @param list<DriveRetailerDTO> $retailers
     */
    public function __construct(
        #[OA\Property(description: "Vrai si la fonction « envoyer au drive » est allumée (interrupteur `DRIVE_ENABLED`) ; faux, toutes les autres routes du drive répondent `404 DRIVE_DISABLED`", example: true)]
        public bool $enabled,
        #[OA\Property(description: 'Enseigne du drive', enum: Retailer::IDS, example: 'leclerc')]
        public string $retailer,
        #[OA\Property(description: "Magasin de l'utilisateur, `null` tant qu'il n'a pas été enregistré", nullable: true)]
        public ?DriveStoreDTO $store,
        #[OA\Property(description: "Une entrée par enseigne **allumée** (`DRIVE_RETAILERS`, DRV-702 ; ordre fixe), avec le magasin de l'utilisateur chez chacune ; s'ajoute aux champs `retailer` et `store`, qui restent ceux de l'enseigne par défaut", type: 'array', items: new OA\Items(ref: new Model(type: DriveRetailerDTO::class)))]
        public array $retailers,
    ) {
    }
}
