<?php

namespace Repas\Repas\Application\AddIngredientToShoppingList;


readonly class AddIngredientToShoppingListCommand
{

    public function __construct(
        public string $ownerId,
        public string $ingredientSlug,
    ) {
    }
}
