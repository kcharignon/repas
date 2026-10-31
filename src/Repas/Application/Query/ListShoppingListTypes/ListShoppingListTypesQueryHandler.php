<?php

namespace Repas\Repas\Application\Query\ListShoppingListTypes;

use Repas\Repas\Application\Loader\ShoppingListTypeLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListShoppingListTypesQueryHandler
{
    public function __construct(
        private ShoppingListTypeLoader $listTypeLoader,
    ) {
    }

    /**
     * @return list<ShoppingListTypeSummaryDTO>
     */
    public function __invoke(ListShoppingListTypesQuery $query): array
    {
        return $this->listTypeLoader->list($query->userId);
    }
}
