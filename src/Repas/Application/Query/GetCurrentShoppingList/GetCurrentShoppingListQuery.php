<?php

namespace Repas\Repas\Application\Query\GetCurrentShoppingList;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetCurrentShoppingListQuery implements QueryInterface
{
    public function __construct(
        public string $ownerId,
    ) {
    }
}
