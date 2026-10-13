<?php

namespace Repas\Repas\Application\Query\ListShoppingLists;

use Repas\Repas\Application\Loader\ShoppingListLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListShoppingListsQueryHandler
{
    public function __construct(
        private ShoppingListLoader $shoppingListLoader,
    ) {
    }

    /**
     * @return list<ShoppingListDTO>
     */
    public function __invoke(ListShoppingListsQuery $query): array
    {
        return $this->shoppingListLoader->listByOwner($query->ownerId);
    }
}
