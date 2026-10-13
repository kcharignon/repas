<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use OpenApi\Attributes as OA;

final readonly class ShoppingListMealDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant du repas planifié', example: '5c1e7d0a-91b2-4a64-8e0f-3b7a6d2c9f20')]
        public string $id,
        #[OA\Property(description: 'Identifiant de la recette', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $recipeId,
        #[OA\Property(example: 'pâtes carbonara')]
        public string $recipeName,
        #[OA\Property(description: 'Nombre de personnes', example: 4)]
        public int $serving,
    ) {
    }
}
