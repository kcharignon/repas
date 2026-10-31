<?php

namespace Repas\Repas\Application\Query\ListOpenShoppingLists;

use OpenApi\Attributes as OA;

final readonly class OpenShoppingListDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la liste en cours', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $id,
        #[OA\Property(description: 'Nom de la liste (celui du type pour une liste de type), ou à défaut la date de création en français', example: 'Boucherie')]
        public string $name,
        #[OA\Property(description: 'Identifiant du type de la liste (ADR 0036), null pour la liste principale', example: null, nullable: true)]
        public ?string $typeId,
        #[OA\Property(description: 'Nombre de lignes de la liste', example: 12)]
        public int $rowCount,
        #[OA\Property(description: 'Nombre de lignes pas encore cochées', example: 5)]
        public int $uncheckedCount,
        #[OA\Property(description: 'Version de la liste (ETag, ADR 0016)', example: 3)]
        public int $version,
    ) {
    }
}
