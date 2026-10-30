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
        #[OA\Property(description: "Enseigne en vigueur : celle de `selection` quand une enseigne est configurée, sinon l'enseigne par défaut du registre si elle est allumée, sinon la première allumée. Avec une seule enseigne allumée, c'est elle", enum: Retailer::IDS, example: 'leclerc')]
        public string $retailer,
        #[OA\Property(description: "Magasin de l'utilisateur chez l'enseigne `retailer`, `null` tant qu'il n'a pas été enregistré", nullable: true)]
        public ?DriveStoreDTO $store,
        #[OA\Property(description: "Une entrée par enseigne **allumée** (`DRIVE_RETAILERS`, DRV-702 ; ordre fixe), avec le magasin de l'utilisateur chez chacune et si c'est son drive habituel (présélectionné à l'envoi) ; s'ajoute aux champs `retailer` et `store`", type: 'array', items: new OA\Items(ref: new Model(type: DriveRetailerDTO::class)))]
        public array $retailers,
        #[OA\Property(description: "Le drive habituel de l'utilisateur (choisi dans « Moi », `PUT /drive/default-retailer`), `null` s'il n'en a pas, ou si son drive habituel est éteint (`DRIVE_RETAILERS`) ou n'a plus de magasin : la sélection l'ignore alors, la préférence reste enregistrée ; quand plusieurs drives sont configurés, c'est la présélection du choix à l'envoi (`selection.mode` vaut `ASK`)", enum: Retailer::IDS, nullable: true)]
        public ?string $defaultRetailer,
        #[OA\Property(description: "La dernière enseigne que l'utilisateur a ouverte (`PUT /drive/last-retailer`), `null` s'il n'en a pas, ou si elle est éteinte ou n'a plus de magasin", enum: Retailer::IDS, nullable: true)]
        public ?string $lastUsedRetailer,
        #[OA\Property(description: "Quelle enseigne utiliser pour envoyer une liste (règle R1 à R5), calculée par le back")]
        public RetailerSelectionDTO $selection,
    ) {
    }
}
