<?php

namespace Repas\Repas\Application\Query\GetRecipe;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class RecipeDTO
{
    /**
     * @param list<RecipeRowDTO> $rows
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de la recette', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $id,
        #[OA\Property(example: 'pâtes carbonara')]
        public string $name,
        #[OA\Property(description: 'Nombre de personnes', example: 4)]
        public int $serving,
        #[OA\Property(description: 'Identifiant du type de recette (slug)', example: 'plat')]
        public string $typeSlug,
        #[OA\Property(example: 'plat')]
        public string $typeName,
        #[OA\Property(description: 'Ingrédients de la recette, dans l\'ordre de la recette (celui de la saisie)', type: 'array', items: new OA\Items(ref: new Model(type: RecipeRowDTO::class)))]
        public array $rows,
        #[OA\Property(description: 'Version de la recette, aussi renvoyée dans l\'en-tête `ETag` : à renvoyer dans `If-Match` pour modifier ou supprimer sans écraser le travail d\'un autre', example: 3)]
        public int $version,
    ) {
    }
}
