<?php

namespace Repas\Drive\Application\Query\ListChoices;

use OpenApi\Attributes as OA;

final readonly class ProductContentDTO
{
    public function __construct(
        #[OA\Property(description: "Quantité contenue, dans l'unité ci-dessous", example: 1000)]
        public float $value,
        #[OA\Property(description: 'Unité du contenu', enum: ['g', 'kg', 'ml', 'cl', 'l', 'piece'], example: 'g')]
        public string $unit,
    ) {
    }
}
