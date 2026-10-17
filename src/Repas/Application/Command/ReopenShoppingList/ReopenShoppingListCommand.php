<?php

namespace Repas\Repas\Application\Command\ReopenShoppingList;


readonly class ReopenShoppingListCommand
{
    public function __construct(
        public string $shoppingListId,
        public ?int $expectedVersion = null,
    ) {
    }
}
