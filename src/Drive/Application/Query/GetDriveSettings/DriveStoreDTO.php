<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use OpenApi\Attributes as OA;

final readonly class DriveStoreDTO
{
    public function __construct(
        #[OA\Property(description: "Identifiant du magasin chez l'enseigne (chiffres, zéros initiaux conservés)", example: '123456')]
        public string $id,
        #[OA\Property(description: 'Nom du magasin tel que lu par l\'agent, si connu', example: 'Ville-Test', nullable: true)]
        public ?string $label,
        #[OA\Property(description: "Adresse de base du magasin sur le site de l'enseigne", example: 'https://exemple.leclercdrive.fr/magasin-123456-123456')]
        public string $baseUrl,
    ) {
    }
}
