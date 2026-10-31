<?php

namespace Repas\Repas\Application\Query\ListOpenShoppingLists;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListOpenShoppingListsQuery implements QueryInterface
{
    public function __construct(
        public string $ownerId,
    ) {
    }
}
