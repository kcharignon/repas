<?php

namespace Repas\Repas\Application\CreateRecipe;


readonly class CreateRecipeRowSubCommand
{
    public function __construct(
        public string $ingredientSlug,
        public string $unitSlug,
        public float $quantity,
    ) {
    }
}
