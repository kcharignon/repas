<?php

namespace Repas\Repas\Application\Command\ActivatedShoppingList;


readonly class ActivatedShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
    )
    {
    }
}
