<?php

namespace Repas\Repas\Application\RemoveRecipe;


readonly class RemoveRecipeCommand
{

    public function __construct(
        public string $recipeId
    ) {
    }
}
