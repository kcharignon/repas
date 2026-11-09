<?php

namespace Repas\Repas\Application\Command\DecreaseShoppingListRowQuantity;

readonly class DecreaseShoppingListRowQuantityCommand
{
    public function __construct(
        public string $shoppingListRowId,
        public ?int $expectedVersion = null,
    ) {
    }
}
