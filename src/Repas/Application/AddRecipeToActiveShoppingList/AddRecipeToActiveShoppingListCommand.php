<?php

namespace Repas\Repas\Application\AddRecipeToActiveShoppingList;


readonly class AddRecipeToActiveShoppingListCommand
{

    public function __construct(
        public string $ownerId,
        public string $recipeId,
    ) {
    }
}
