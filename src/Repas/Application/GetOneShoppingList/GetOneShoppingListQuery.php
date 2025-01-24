<?php

namespace Repas\Repas\Application\GetOneShoppingList;


readonly class GetOneShoppingListQuery
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
