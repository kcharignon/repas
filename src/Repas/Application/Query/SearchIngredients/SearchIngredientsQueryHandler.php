<?php

namespace Repas\Repas\Application\Query\SearchIngredients;

use Repas\Repas\Application\Loader\IngredientLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class SearchIngredientsQueryHandler
{
    public function __construct(
        private IngredientLoader $ingredientLoader,
    ) {
    }

    /**
     * @return list<IngredientSearchResultDTO>
     */
    public function __invoke(SearchIngredientsQuery $query): array
    {
        return $this->ingredientLoader->search($query->search, $query->viewerId, $query->viewerIsAdmin);
    }
}
