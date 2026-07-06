<?php

namespace Repas\Repas\Application\Command\PausedShoppingList;


readonly class PausedShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
