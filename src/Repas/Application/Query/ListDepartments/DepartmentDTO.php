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
        #[OA\Property(description: "Nom de l'icône du rayon (le front la dessine), `null` quand le rayon n'en a pas : elle distingue deux ingrédients de même nom dans deux rayons. Clé toujours écrite ; les valeurs permises sont celles du corps de `PUT /departments/{slug}` ; une icône que le front ne connaît pas s'ignore", type: 'string', example: 'snowflake', nullable: true)]
        public ?string $icon,
    ) {
    }
}
