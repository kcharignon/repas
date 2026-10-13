<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ShoppingListRecipeTypeDTO
{
    /**
     * @param list<ShoppingListMealDTO> $meals
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant du type de recette (slug)', example: 'plat')]
        public string $slug,
        #[OA\Property(example: 'plat')]
        public string $name,
        #[OA\Property(description: 'Chemin de l\'image du type')]
        public string $image,
        #[OA\Property(description: 'Repas planifiés de ce type, triés par nom de recette', type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListMealDTO::class)))]
        public array $meals,
    ) {
    }
}
