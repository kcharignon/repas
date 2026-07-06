<?php

namespace Repas\Repas\Application\Command\UncheckLineOnShoppingList;


readonly class UncheckLineOnShoppingListCommand
{

    public function __construct(
        public string $shoppingListRowId,
    ) {
    }
}
