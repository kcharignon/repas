<?php

namespace Repas\Repas\Application\Command\RemoveRecipe;


readonly class RemoveRecipeCommand
{

    public function __construct(
        public string $recipeId
    ) {
    }
}
