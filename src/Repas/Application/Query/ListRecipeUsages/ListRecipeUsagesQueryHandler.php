<?php

namespace Repas\Repas\Application\Query\ListRecipeUsages;

use Repas\Repas\Application\Loader\RecipeLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListRecipeUsagesQueryHandler
{
    public function __construct(
        private RecipeLoader $recipeLoader,
    ) {
    }

    public function __invoke(ListRecipeUsagesQuery $query): RecipeUsagesDTO
    {
        return $this->recipeLoader->listUsages($query->recipeId, $query->userId);
    }
}
