<?php

namespace Repas\Repas\Application\RemoveIngredientToShoppingList;


readonly class RemoveIngredientToShoppingListCommand
{

    public function __construct(
        public string $ownerId,
        public string $ingredientSlug,
    ) {
    }
}
