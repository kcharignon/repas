<?php

namespace Repas\Repas\Application\Query\ListCompatibleUnits;

use OpenApi\Attributes as OA;

final readonly class CompatibleUnitDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'unité (slug)', example: 'gramme')]
        public string $slug,
        #[OA\Property(description: 'Nom de l\'unité, première lettre en majuscule', example: 'Gramme')]
        public string $name,
    ) {
    }
}
