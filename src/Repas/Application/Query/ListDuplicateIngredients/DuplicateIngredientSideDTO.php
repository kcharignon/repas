<?php

namespace Repas\Repas\Application\Query\ListDuplicateIngredients;

use OpenApi\Attributes as OA;

/**
 * Un des deux ingrédients d'une proposition : de quoi afficher sa ligne (« Tomate · 12 recettes · 3 listes ») sans second appel.
 */
final readonly class DuplicateIngredientSideDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'tomate-0a1b2c3d')]
        public string $slug,
        #[OA\Property(example: 'Tomate')]
        public string $name,
        #[OA\Property(description: 'Identifiant du rayon (slug) : le même pour les deux ingrédients de la proposition', example: 'fruit-et-legume')]
        public string $departmentSlug,
        #[OA\Property(example: 'Fruit & légume')]
        public string $departmentName,
        #[OA\Property(description: 'Nom de l\'unité d\'achat', example: 'kilo')]
        public string $purchaseUnitName,
        #[OA\Property(description: 'Nombre de recettes de l\'utilisateur qui utilisent cet ingrédient (0 s\'il n\'en a aucune)', example: 12)]
        public int $recipeCount,
        #[OA\Property(description: 'Nombre de listes de courses de l\'utilisateur qui utilisent cet ingrédient (0 s\'il n\'en a aucune)', example: 3)]
        public int $listCount,
    ) {
    }
}
