<?php

namespace Repas\Repas\Application\Query\ListShoppingListTypes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListShoppingListTypesQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
