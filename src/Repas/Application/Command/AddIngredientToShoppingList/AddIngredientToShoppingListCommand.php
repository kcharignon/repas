<?php

namespace Repas\Repas\Application\Command\AddIngredientToShoppingList;


readonly class AddIngredientToShoppingListCommand
{

    public function __construct(
        public string $ownerId,
        public string $ingredientSlug,
    ) {
    }
}
