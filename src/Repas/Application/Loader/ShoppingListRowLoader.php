<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Repas\Domain\Exception\ShoppingListRowException;

interface ShoppingListRowLoader
{
    /**
     * @throws ShoppingListRowException
     */
    public function get(string $rowId): ShoppingListRowDTO;
}
