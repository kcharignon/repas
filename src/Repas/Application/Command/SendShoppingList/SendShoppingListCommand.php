<?php

namespace Repas\Repas\Application\Command\SendShoppingList;


readonly class SendShoppingListCommand
{
    public function __construct(
        public string $shoppingListId,
        public ?int $expectedVersion = null,
    ) {
    }
}
