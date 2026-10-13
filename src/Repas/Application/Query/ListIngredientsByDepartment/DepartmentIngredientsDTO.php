<?php

namespace Repas\Repas\Application\Query\ListIngredientsByDepartment;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;

final readonly class DepartmentIngredientsDTO
{
    /**
     * @param list<IngredientRowDTO> $ingredients
     */
    public function __construct(
        #[OA\Property(ref: new Model(type: DepartmentDTO::class), description: 'Le rayon demandé')]
        public DepartmentDTO $department,
        #[OA\Property(description: 'Ingrédients communs et ingrédients de l\'utilisateur dans ce rayon, triés par nom', type: 'array', items: new OA\Items(ref: new Model(type: IngredientRowDTO::class)))]
        public array $ingredients,
    ) {
    }
}
