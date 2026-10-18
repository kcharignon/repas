<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetDrivePlanQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        public string $shoppingListId,
    ) {
    }
}
