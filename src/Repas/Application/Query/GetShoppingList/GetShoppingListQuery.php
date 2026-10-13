<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetShoppingListQuery implements QueryInterface
{
    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
