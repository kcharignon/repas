<?php

namespace Repas\Repas\Application\RemoveIngredient;


readonly class RemoveIngredientCommand
{

    public function __construct(
        public string $ingredientId,
    ) {
    }
}
