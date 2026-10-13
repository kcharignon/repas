<?php

namespace Repas\Repas\Application\Query\GetActiveShoppingListSummary;

use Repas\Repas\Application\Loader\ShoppingListLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetActiveShoppingListSummaryQueryHandler
{
    public function __construct(
        private ShoppingListLoader $shoppingListLoader,
    ) {
    }

    public function __invoke(GetActiveShoppingListSummaryQuery $query): ActiveShoppingListSummaryDTO
    {
        return $this->shoppingListLoader->getActiveSummary($query->ownerId);
    }
}
