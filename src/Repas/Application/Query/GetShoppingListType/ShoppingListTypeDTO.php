<?php

namespace Repas\Repas\Application\Query\GetShoppingListType;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ShoppingListTypeDTO
{
    /**
     * @param list<ShoppingListTypeDepartmentDTO> $departments
     * @param list<ShoppingListTypeIngredientDTO> $ingredients
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant du type de liste', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $id,
        #[OA\Property(example: 'Boucherie')]
        public string $name,
        #[OA\Property(description: 'Rayons rattachés à ce type, triés par identifiant : les produits de ces rayons vont dans la liste de ce type', type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListTypeDepartmentDTO::class)))]
        public array $departments,
        #[OA\Property(description: "Produits à part de ce type : ceux de l'utilisateur dont le type est choisi à la main, triés par nom (puis par identifiant)", type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListTypeIngredientDTO::class)))]
        public array $ingredients,
    ) {
    }
}
