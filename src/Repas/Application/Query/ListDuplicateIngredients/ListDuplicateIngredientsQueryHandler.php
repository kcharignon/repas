<?php

namespace Repas\Repas\Application\Query\ListDuplicateIngredients;

use Repas\Repas\Application\Loader\IngredientDuplicateLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListDuplicateIngredientsQueryHandler
{
    public function __construct(
        private IngredientDuplicateLoader $duplicateLoader,
    ) {
    }

    /**
     * @return list<DuplicateIngredientDTO>
     */
    public function __invoke(ListDuplicateIngredientsQuery $query): array
    {
        return $this->duplicateLoader->duplicates($query->userId);
    }
}
