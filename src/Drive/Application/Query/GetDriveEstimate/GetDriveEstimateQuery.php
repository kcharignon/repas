<?php

namespace Repas\Drive\Application\Query\GetDriveEstimate;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetDriveEstimateQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        public string $shoppingListId,
    ) {
    }
}
