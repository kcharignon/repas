<?php

namespace Repas\Repas\Application\ActivatedShoppingList;


readonly class ActivatedShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    )
    {
    }
}
