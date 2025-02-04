<?php

namespace Repas\Repas\Application\TickLineOnShoppingList;


readonly class TickLineOnShoppingListCommand
{
    public function __construct(
        public string $shoppingListRowId,
    ) {
    }

}
