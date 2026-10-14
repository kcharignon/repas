<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateDepartment;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de un rayon, pour la création (`POST`) comme pour le remplacement complet (`PUT`).
 */
final readonly class DepartmentPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Nom du rayon (son slug en dérive et ne change plus)", example: 'Surgelés')]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Assert\Regex(pattern: '#^(https://[^\s]+|images/[A-Za-z0-9_\-./]+)$#', message: "L'image est une URL https ou un chemin relatif commençant par images/.")]
        #[OA\Property(description: "URL https ou chemin relatif (images/…) de l'image", example: 'images/frozen.svg')]
        public string $image,
    ) {
    }
}
