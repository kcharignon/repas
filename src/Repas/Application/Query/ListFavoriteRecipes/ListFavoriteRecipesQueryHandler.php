<?php

namespace Repas\Repas\Application\Query\ListFavoriteRecipes;

use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListFavoriteRecipesQueryHandler
{
    public function __construct(
        private RecipeLoader $recipeLoader,
    ) {
    }

    /**
     * @return list<RecipeSummaryDTO>
     */
    public function __invoke(ListFavoriteRecipesQuery $query): array
    {
        return $this->recipeLoader->listFavoritesOfAuthor($query->authorId);
    }
}
