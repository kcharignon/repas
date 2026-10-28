<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use OpenApi\Attributes as OA;

final readonly class OtherPeopleRecipeDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la recette', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $id,
        #[OA\Property(example: 'pâtes carbonara')]
        public string $name,
        #[OA\Property(description: 'Nom du type de recette (la catégorie)', example: 'plat')]
        public string $typeName,
        #[OA\Property(description: 'Identifiant stable du type de recette, pour grouper les recettes par catégorie', example: 'plat')]
        public string $typeSlug,
    ) {
    }
}
