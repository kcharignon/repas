<?php

namespace Repas\Repas\Application\Query\ListCompatibleUnits;

use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Domain\Exception\IngredientException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListCompatibleUnitsQueryHandler
{
    public function __construct(
        private IngredientLoader $ingredientLoader,
    ) {
    }

    /**
     * @return list<CompatibleUnitDTO>
     * @throws IngredientException
     */
    public function __invoke(ListCompatibleUnitsQuery $query): array
    {
        return $this->ingredientLoader->listCompatibleUnits($query->ingredientSlug);
    }
}
