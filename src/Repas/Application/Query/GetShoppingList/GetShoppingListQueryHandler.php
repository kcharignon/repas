<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetShoppingListQueryHandler
{
    public function __construct(
        private ShoppingListLoader $shoppingListLoader,
    ) {
    }

    /**
     * @throws ShoppingListException
     */
    public function __invoke(GetShoppingListQuery $query): ShoppingListDetailDTO
    {
        return $this->shoppingListLoader->get($query->shoppingListId);
    }
}
