<?php

namespace Repas\Repas\Application\Query\ListAvailableIngredients;

use OpenApi\Attributes as OA;

final readonly class IngredientOptionDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'oeuf')]
        public string $slug,
        #[OA\Property(example: 'œuf')]
        public string $name,
        #[OA\Property(description: 'Identifiant du rayon de l\'ingrédient (slug) : deux ingrédients de même nom dans deux rayons se distinguent par lui', example: 'surgele')]
        public string $departmentSlug,
        #[OA\Property(description: 'Nom du rayon de l\'ingrédient', example: 'Surgelé')]
        public string $departmentName,
    ) {
    }
}
