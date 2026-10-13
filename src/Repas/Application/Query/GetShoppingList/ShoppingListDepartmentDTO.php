<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ShoppingListDepartmentDTO
{
    /**
     * @param list<ShoppingListRowDTO> $rows
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'fruit-et-legume')]
        public string $slug,
        #[OA\Property(example: 'Fruit & légume')]
        public string $name,
        #[OA\Property(description: 'Chemin de l\'image du rayon')]
        public string $image,
        #[OA\Property(description: 'Lignes de ce rayon, triées par slug d\'ingrédient', type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListRowDTO::class)))]
        public array $rows,
    ) {
    }
}
