<?php

namespace Repas\Repas\Application\CreateShoppingList;


class CreateShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
        public string $ownerId,
    ) {
    }
}
