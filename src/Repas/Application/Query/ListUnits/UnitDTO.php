<?php

namespace Repas\Repas\Application\Query\ListUnits;

use OpenApi\Attributes as OA;

final readonly class UnitDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'unité (slug)', example: 'gramme')]
        public string $slug,
        #[OA\Property(example: 'gramme')]
        public string $name,
        #[OA\Property(description: 'Symbole affiché à côté d\'une quantité (peut être vide)', example: 'g')]
        public string $symbol,
    ) {
    }
}
