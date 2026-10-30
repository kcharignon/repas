<?php

namespace Repas\Repas\Application\Query\ListConversions;

use OpenApi\Attributes as OA;

final readonly class ConversionDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la conversion', example: '9967014a-7c8b-423f-9b96-04fc13943a05')]
        public string $id,
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug), null pour une conversion commune à tous', example: 'beurre', nullable: true)]
        public ?string $ingredientSlug,
        #[OA\Property(example: 'beurre', nullable: true)]
        public ?string $ingredientName,
        #[OA\Property(description: 'Email du propriétaire de l\'ingrédient (toujours le lecteur), null pour une conversion générale', nullable: true)]
        public ?string $creatorEmail,
        #[OA\Property(description: 'Identifiant de l\'unité de départ (slug)', example: 'plaquette')]
        public string $startUnitSlug,
        #[OA\Property(example: 'plaquette')]
        public string $startUnitName,
        #[OA\Property(description: 'Identifiant de l\'unité d\'arrivée (slug)', example: 'gramme')]
        public string $endUnitSlug,
        #[OA\Property(example: 'gramme')]
        public string $endUnitName,
        #[OA\Property(description: 'Une unité de départ vaut ce nombre d\'unités d\'arrivée', example: 250)]
        public float $coefficient,
        #[OA\Property(description: 'Identifiant du rayon de l\'ingrédient (slug), null pour une conversion commune à tous', example: 'epicerie', nullable: true)]
        public ?string $departmentSlug,
        #[OA\Property(description: 'Nom du rayon de l\'ingrédient, null pour une conversion commune à tous', example: 'Épicerie', nullable: true)]
        public ?string $departmentName,
    ) {
    }
}
