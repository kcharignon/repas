<?php

namespace Repas\Repas\Application\Query\ListOpenShoppingLists;

use Repas\Repas\Application\Loader\ShoppingListLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListOpenShoppingListsQueryHandler
{
    public function __construct(
        private ShoppingListLoader $shoppingListLoader,
    ) {
    }

    /**
     * @return list<OpenShoppingListDTO>
     */
    public function __invoke(ListOpenShoppingListsQuery $query): array
    {
        return $this->shoppingListLoader->listOpenByOwner($query->ownerId);
    }
}
