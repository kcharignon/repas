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
    ) {
    }
}
