<?php

namespace Repas\Repas\Application\Command\RemoveIngredient;


readonly class RemoveIngredientCommand
{

    public function __construct(
        public string $ingredientId,
    ) {
    }
}
