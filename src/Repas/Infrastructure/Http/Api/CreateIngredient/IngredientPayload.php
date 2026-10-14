<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateIngredient;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'un ingrédient, pour la création (`POST`) comme pour le remplacement complet (`PUT`). L'image n'est pas
 * modifiable par l'API : elle reste celle de l'ingrédient.
 */
final readonly class IngredientPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Nom de l'ingrédient", example: 'Praliné')]
        public string $name,
        #[Assert\NotBlank]
        #[OA\Property(description: 'Slug du rayon', example: 'fruit-et-legume')]
        public string $departmentSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité utilisée dans les recettes", example: 'gramme')]
        public string $defaultCookingUnitSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité d'achat", example: 'kilo')]
        public string $defaultPurchaseUnitSlug,
        #[Assert\Positive]
        #[OA\Property(description: "Une unité d'achat vaut ce nombre d'unités de recette ; obligatoire quand les deux unités diffèrent et qu'aucune conversion ne les relie déjà", example: 1000, nullable: true)]
        public ?float $coefficient = null,
    ) {
    }
}
