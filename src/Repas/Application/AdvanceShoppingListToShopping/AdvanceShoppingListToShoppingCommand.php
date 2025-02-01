<?php

namespace Repas\Repas\Application\AdvanceShoppingListToShopping;


readonly class AdvanceShoppingListToShoppingCommand
{

    public function __construct(public string $shoppingListId)
    {
    }
}
