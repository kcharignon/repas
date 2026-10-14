<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'une recette, pour la création (`POST`) comme pour le remplacement complet (`PUT`).
 */
final readonly class RecipePayload
{
    /**
     * @param list<RecipeRowPayload> $rows
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: 'Nom de la recette', example: 'Pâtes carbonara')]
        public string $name,
        #[Assert\Range(min: 1, max: 100)]
        #[OA\Property(description: 'Nombre de personnes', example: 4)]
        public int $serving,
        #[Assert\NotBlank]
        #[OA\Property(description: 'Slug du type de recette', example: 'plat')]
        public string $typeSlug,
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        #[OA\Property(description: 'Lignes de la recette, au moins une', type: 'array', items: new OA\Items(ref: new Model(type: RecipeRowPayload::class)))]
        public array $rows,
    ) {
    }
}
