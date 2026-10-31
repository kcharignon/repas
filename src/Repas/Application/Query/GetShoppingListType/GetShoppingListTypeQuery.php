<?php

namespace Repas\Repas\Application\Query\GetShoppingListType;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetShoppingListTypeQuery implements QueryInterface
{
    public function __construct(
        public string $id,
        public string $userId,
    ) {
    }
}
