<?php

namespace Repas\Repas\Application\Command\UpdateShoppingList;


readonly class UpdateShoppingListCommand
{

    public function __construct(
        public string $shoppingListId,
        public ?string $newName,
    ) {
    }
}
