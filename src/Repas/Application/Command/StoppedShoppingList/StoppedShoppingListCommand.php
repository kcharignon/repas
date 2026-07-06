<?php

namespace Repas\Repas\Application\Command\StoppedShoppingList;


readonly class StoppedShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
