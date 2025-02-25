<?php

namespace Repas\Repas\Application\StoppedShoppingList;


readonly class StoppedShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
