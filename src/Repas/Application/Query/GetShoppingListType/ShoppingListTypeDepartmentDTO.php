<?php

namespace Repas\Repas\Application\Query\GetShoppingListType;

use OpenApi\Attributes as OA;

final readonly class ShoppingListTypeDepartmentDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'viandes')]
        public string $slug,
        #[OA\Property(example: 'Viandes')]
        public string $name,
    ) {
    }
}
