<?php

namespace Repas\Repas\Application\Query\ListRecipeUsages;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListRecipeUsagesQuery implements QueryInterface
{
    /**
     * @param string $recipeId la recette dont on cherche les usages ; le droit `RECIPE_OWNER` est jugé à la frontière HTTP
     * @param string $userId l'utilisateur qui lit : il ne voit pas la recette secrète d'un autre, qui l'utiliserait
     */
    public function __construct(
        public string $recipeId,
        public string $userId,
    ) {
    }
}
