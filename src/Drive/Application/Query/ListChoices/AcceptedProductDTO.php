<?php

namespace Repas\Drive\Application\Query\ListChoices;

use OpenApi\Attributes as OA;

final readonly class AcceptedProductDTO
{
    public function __construct(
        #[OA\Property(description: 'Rang du produit : 1 = le préféré, les suivants servent de secours', example: 1)]
        public int $rank,
        public ChoiceProductDTO $product,
    ) {
    }
}
