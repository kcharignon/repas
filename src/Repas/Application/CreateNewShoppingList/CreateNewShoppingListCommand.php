<?php

namespace Repas\Repas\Application\CreateNewShoppingList;


class CreateNewShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
        public string $ownerId,
    ) {
    }
}
