<?php

namespace Repas\Repas\Application\Query\ListIngredientsToFix;

use Repas\Repas\Application\Loader\IngredientToFixLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListIngredientsToFixQueryHandler
{
    public function __construct(
        private IngredientToFixLoader $toFixLoader,
    ) {
    }

    public function __invoke(ListIngredientsToFixQuery $query): IngredientsToFixDTO
    {
        return $this->toFixLoader->toFix($query->userId);
    }
}
