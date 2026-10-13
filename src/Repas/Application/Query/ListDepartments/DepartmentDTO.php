<?php

namespace Repas\Repas\Application\Query\ListDepartments;

use OpenApi\Attributes as OA;

final readonly class DepartmentDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'fruits-et-legumes')]
        public string $slug,
        #[OA\Property(example: 'Fruits et légumes')]
        public string $name,
        #[OA\Property(description: 'Chemin ou URL de l\'image du rayon', example: 'images/department/fruits.png')]
        public string $image,
    ) {
    }
}
