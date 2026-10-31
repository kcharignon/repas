<?php

namespace Repas\Repas\Application\Query\ListShoppingListTypes;

use OpenApi\Attributes as OA;

final readonly class ShoppingListTypeSummaryDTO
{
    /**
     * @param list<string> $departmentSlugs
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant du type de liste', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $id,
        #[OA\Property(example: 'Boucherie')]
        public string $name,
        #[OA\Property(description: 'Identifiants (slugs) des rayons rattachés à ce type, triés par identifiant : les produits de ces rayons vont dans la liste de ce type', type: 'array', items: new OA\Items(type: 'string', example: 'viandes'))]
        public array $departmentSlugs,
        #[OA\Property(description: "Nombre de produits à part de ce type : ceux de l'utilisateur dont le type est choisi à la main (jamais ceux d'un autre), toujours renseigné", example: 2)]
        public int $ingredientCount,
    ) {
    }
}
