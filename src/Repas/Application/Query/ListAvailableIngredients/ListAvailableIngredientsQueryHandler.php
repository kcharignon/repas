<?php

namespace Repas\Repas\Application\Query\ListAvailableIngredients;

use Repas\Repas\Application\Loader\IngredientLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListAvailableIngredientsQueryHandler
{
    public function __construct(
        private IngredientLoader $ingredientLoader,
    ) {
    }

    /**
     * @return list<IngredientOptionDTO>
     */
    public function __invoke(ListAvailableIngredientsQuery $query): array
    {
        return $this->ingredientLoader->listAvailable($query->userId, $query->search);
    }
}
