<?php

namespace Repas\Repas\Application\RemoveShoppingList;


readonly class RemoveShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
