<?php

namespace Repas\Repas\Application\Query\ListIngredientConversions;

use OpenApi\Attributes as OA;

final readonly class IngredientConversionDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la conversion', example: '9967014a-7c8b-423f-9b96-04fc13943a05')]
        public string $id,
        #[OA\Property(description: 'Identifiant de l\'unité de départ (slug)', example: 'cuillere-a-soupe')]
        public string $startUnitSlug,
        #[OA\Property(example: 'cuillère à soupe')]
        public string $startUnitName,
        #[OA\Property(description: 'Identifiant de l\'unité d\'arrivée (slug)', example: 'gramme')]
        public string $endUnitSlug,
        #[OA\Property(example: 'gramme')]
        public string $endUnitName,
        #[OA\Property(description: '1 unité de départ = ce nombre d\'unités d\'arrivée', example: 10)]
        public float $coefficient,
    ) {
    }
}
