<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipeType;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de un type de recette, pour la création (`POST`) comme pour le remplacement complet (`PUT`).
 */
final readonly class RecipeTypePayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Nom du type (son slug en dérive et ne change plus)", example: 'Apéritif')]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Assert\Regex(pattern: '#^(https://[^\s]+|images/[A-Za-z0-9_\-./]+)$#', message: "L'image est une URL https ou un chemin relatif commençant par images/.")]
        #[OA\Property(description: "URL https ou chemin relatif (images/…) de l'image", example: 'images/aperitif.svg')]
        public string $image,
        #[Assert\Range(min: 0, max: 1000)]
        #[OA\Property(description: "Rang d'affichage, du plus petit au plus grand", example: 4)]
        public int $order,
    ) {
    }
}
