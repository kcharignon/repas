<?php

namespace Repas\Repas\Application\RemoveRecipeToActiveShoppingList;


readonly class RemoveRecipeToActiveShoppingListCommand
{

    public function __construct(
        public string $ownerId,
        public string $recipeId,
    ) {
    }
}
