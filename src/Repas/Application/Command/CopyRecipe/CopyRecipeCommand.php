<?php

namespace Repas\Repas\Application\Command\CopyRecipe;


readonly class CopyRecipeCommand
{

    public function __construct(
        public string $recipeId,
        public string $authorId,
    ) {
    }
}
