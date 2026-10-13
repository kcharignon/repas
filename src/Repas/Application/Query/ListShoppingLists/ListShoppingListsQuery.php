<?php

namespace Repas\Repas\Application\Query\ListShoppingLists;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListShoppingListsQuery implements QueryInterface
{
    public function __construct(
        public string $ownerId,
    ) {
    }
}
