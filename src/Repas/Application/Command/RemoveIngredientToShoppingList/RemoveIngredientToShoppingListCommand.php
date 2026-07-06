<?php

namespace Repas\Repas\Application\Command\RemoveIngredientToShoppingList;


readonly class RemoveIngredientToShoppingListCommand
{

    public function __construct(
        public string $ownerId,
        public string $ingredientSlug,
    ) {
    }
}
