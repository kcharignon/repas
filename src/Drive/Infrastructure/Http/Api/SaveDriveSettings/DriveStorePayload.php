<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveDriveSettings;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DriveStorePayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        #[OA\Property(description: "Identifiant du magasin chez l'enseigne : celui de l'adresse de base", example: '123456')]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Adresse de base du magasin : `https://<sous-domaine>.leclercdrive.fr/magasin-<id>-<id>`", example: 'https://exemple.leclercdrive.fr/magasin-123456-123456')]
        public string $baseUrl,
        #[Assert\Length(max: 255)]
        #[OA\Property(description: 'Nom du magasin tel que lu par l\'agent (255 caractères au plus)', example: 'Ville-Test', nullable: true)]
        public ?string $label = null,
    ) {
    }
}
