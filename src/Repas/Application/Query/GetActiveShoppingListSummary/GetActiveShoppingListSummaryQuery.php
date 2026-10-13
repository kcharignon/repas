<?php

namespace Repas\Repas\Application\Query\GetActiveShoppingListSummary;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetActiveShoppingListSummaryQuery implements QueryInterface
{
    public function __construct(
        public string $ownerId,
    ) {
    }
}
