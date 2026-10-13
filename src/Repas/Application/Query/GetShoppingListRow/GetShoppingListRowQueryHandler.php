<?php

namespace Repas\Repas\Application\Query\GetShoppingListRow;

use Repas\Repas\Application\Loader\ShoppingListRowLoader;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetShoppingListRowQueryHandler
{
    public function __construct(
        private ShoppingListRowLoader $shoppingListRowLoader,
    ) {
    }

    /**
     * @throws ShoppingListRowException
     */
    public function __invoke(GetShoppingListRowQuery $query): ShoppingListRowDTO
    {
        return $this->shoppingListRowLoader->get($query->rowId);
    }
}
