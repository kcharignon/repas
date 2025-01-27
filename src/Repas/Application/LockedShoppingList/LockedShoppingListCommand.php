<?php

namespace Repas\Repas\Application\LockedShoppingList;


readonly class LockedShoppingListCommand
{

    public function __construct(public string $shoppingListId)
    {
    }
}
