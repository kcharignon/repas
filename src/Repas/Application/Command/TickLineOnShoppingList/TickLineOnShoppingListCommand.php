<?php

namespace Repas\Repas\Application\Command\TickLineOnShoppingList;


readonly class TickLineOnShoppingListCommand
{
    public function __construct(
        public string $shoppingListRowId,
    ) {
    }

}
