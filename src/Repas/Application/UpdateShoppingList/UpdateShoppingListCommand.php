<?php

namespace Repas\Repas\Application\UpdateShoppingList;


readonly class UpdateShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
        public ?string $newName,
    ) {
    }
}
