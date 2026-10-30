<?php

namespace Repas\Repas\Application\Query\ListRecipeTypes;

use OpenApi\Attributes as OA;

final readonly class RecipeTypeCountDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant du type de recette (slug)', example: 'plat')]
        public string $slug,
        #[OA\Property(example: 'plat')]
        public string $name,
        #[OA\Property(description: 'Chemin ou URL de l\'image du type', example: 'images/recipe/type/meal.svg')]
        public string $image,
        #[OA\Property(description: 'Position d\'affichage (croissante)', example: 2)]
        public int $order,
        #[OA\Property(description: 'Nombre de recettes de la personne dans ce type, pour masquer les catégories vides', example: 7)]
        public int $recipeCount,
    ) {
    }
}
