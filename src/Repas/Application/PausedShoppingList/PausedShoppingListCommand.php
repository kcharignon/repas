<?php

namespace Repas\Repas\Application\PausedShoppingList;


readonly class PausedShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
