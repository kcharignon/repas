<?php

namespace Repas\Repas\Application\Query\ListIngredientsToFix;

use OpenApi\Attributes as OA;

/**
 * Un ingrédient de l'utilisateur dont l'unité des recettes ne se convertit pas en son unité d'achat : de quoi l'afficher et ouvrir sa fiche.
 */
final readonly class IngredientToFixDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'biscotte-1a2b3c')]
        public string $slug,
        #[OA\Property(example: 'biscotte')]
        public string $name,
        #[OA\Property(description: 'Nom du rayon de l\'ingrédient', example: 'Épicerie')]
        public string $departmentName,
        #[OA\Property(description: 'Unité des recettes de l\'ingrédient (slug)', example: 'unite')]
        public string $cookingUnitSlug,
        #[OA\Property(example: 'unité')]
        public string $cookingUnitName,
        #[OA\Property(description: 'Unité d\'achat de l\'ingrédient (slug)', example: 'paquet')]
        public string $purchaseUnitSlug,
        #[OA\Property(example: 'paquet')]
        public string $purchaseUnitName,
    ) {
    }
}
