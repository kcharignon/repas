<?php

namespace Repas\Repas\Application\Command\IncreaseShoppingListRowQuantity;

readonly class IncreaseShoppingListRowQuantityCommand
{
    public function __construct(
        public string $shoppingListRowId,
        public ?int $expectedVersion = null,
    ) {
    }
}
