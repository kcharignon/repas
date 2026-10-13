<?php

namespace Repas\Repas\Application\Query\ListRecipeTypes;

use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListRecipeTypesQueryHandler
{
    public function __construct(
        private RecipeTypeLoader $recipeTypeLoader,
    ) {
    }

    /**
     * @return list<RecipeTypeDTO>
     */
    public function __invoke(ListRecipeTypesQuery $query): array
    {
        return $this->recipeTypeLoader->list();
    }
}
