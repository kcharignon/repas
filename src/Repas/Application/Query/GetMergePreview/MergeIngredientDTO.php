<?php

namespace Repas\Repas\Application\Query\GetMergePreview;

use OpenApi\Attributes as OA;

final readonly class MergeIngredientDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'tomate')]
        public string $slug,
        #[OA\Property(example: 'Tomate')]
        public string $name,
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'fruit-et-legume')]
        public string $departmentSlug,
        #[OA\Property(example: 'Fruit & légume')]
        public string $departmentName,
        #[OA\Property(description: 'Nom de l\'unité d\'achat', example: 'kilo')]
        public string $purchaseUnitName,
    ) {
    }
}
