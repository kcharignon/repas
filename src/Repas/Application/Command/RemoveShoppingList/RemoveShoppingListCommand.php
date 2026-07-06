<?php

namespace Repas\Repas\Application\Command\RemoveShoppingList;


readonly class RemoveShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
