<?php

namespace Repas\Repas\Application\UncheckLineOnShoppingList;


readonly class UncheckLineOnShoppingListCommand
{

    public function __construct(
        public string $shoppingListRowId,
    ) {
    }
}
