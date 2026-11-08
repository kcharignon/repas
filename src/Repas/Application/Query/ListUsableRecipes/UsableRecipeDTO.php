<?php

namespace Repas\Repas\Application\Query\ListUsableRecipes;

use OpenApi\Attributes as OA;

final readonly class UsableRecipeDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la recette', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $id,
        #[OA\Property(example: 'Pâte sablée')]
        public string $name,
        #[OA\Property(description: "Nombre de personnes pour lequel la recette est écrite (il ne sert qu'à l'affichage : le nombre de fois qu'elle est utilisée ne dépend pas de lui)", example: 6)]
        public int $serving,
        #[OA\Property(description: 'Identifiant stable du type de la recette', example: 'dessert')]
        public string $typeSlug,
        #[OA\Property(description: 'Nom du type de la recette', example: 'Dessert')]
        public string $typeName,
        #[OA\Property(description: "Recette secrète. Toujours écrit ; vrai seulement quand la recette écrite l'est aussi (`secret=true`) et que l'utilisateur est son auteur", example: false)]
        public bool $secret,
    ) {
    }
}
