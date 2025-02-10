<?php

namespace Repas\Repas\Application\UpdateRecipe;


readonly class UpdateRecipeRowSubCommand
{
    public function __construct(
        public string $ingredientSlug,
        public string $unitSlug,
        public float $quantity,
    ) {
    }
}
