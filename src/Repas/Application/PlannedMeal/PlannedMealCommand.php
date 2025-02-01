<?php

namespace Repas\Repas\Application\PlannedMeal;


readonly class PlannedMealCommand
{

    public function __construct(
        public string $ownerId,
        public string $recipeId,
    ) {
    }
}
