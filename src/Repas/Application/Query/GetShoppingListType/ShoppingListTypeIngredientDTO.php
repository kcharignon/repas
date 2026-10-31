<?php

namespace Repas\Repas\Application\Query\GetShoppingListType;

use OpenApi\Attributes as OA;

final readonly class ShoppingListTypeIngredientDTO
{
    public function __construct(
        #[OA\Property(description: "Identifiant de l'ingrédient (slug)", example: 'saucisse-de-toulouse')]
        public string $slug,
        #[OA\Property(example: 'Saucisse de Toulouse')]
        public string $name,
        #[OA\Property(description: "Identifiant du rayon de l'ingrédient (slug) : deux produits de même nom peuvent être dans deux rayons", example: 'charcuterie')]
        public string $departmentSlug,
        #[OA\Property(example: 'Charcuterie')]
        public string $departmentName,
    ) {
    }
}
