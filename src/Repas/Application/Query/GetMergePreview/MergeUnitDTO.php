<?php

namespace Repas\Repas\Application\Query\GetMergePreview;

use OpenApi\Attributes as OA;

final readonly class MergeUnitDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'unité (slug)', example: 'tranche')]
        public string $slug,
        #[OA\Property(example: 'tranche')]
        public string $name,
    ) {
    }
}
