<?php

namespace Repas\Repas\Application\Query\GetShoppingListRow;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetShoppingListRowQuery implements QueryInterface
{
    public function __construct(
        public string $rowId,
    ) {
    }
}
