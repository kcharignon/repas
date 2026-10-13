<?php

namespace Repas\Repas\Application\Query\ListRecipesByType;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;

final readonly class RecipeTypeRecipesDTO
{
    /**
     * @param list<RecipeSummaryDTO> $recipes
     */
    public function __construct(
        #[OA\Property(ref: new Model(type: RecipeTypeDTO::class), description: 'Le type de recette demandé')]
        public RecipeTypeDTO $recipeType,
        #[OA\Property(description: 'Recettes de l\'utilisateur pour ce type, triées par slug', type: 'array', items: new OA\Items(ref: new Model(type: RecipeSummaryDTO::class)))]
        public array $recipes,
    ) {
    }
}
