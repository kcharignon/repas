<?php

namespace Repas\Repas\Application\Command\UpdateServingMeal;


readonly class UpdateServingMealCommand
{

    public function __construct(
        public string $mealId,
        public int $serving,
    ) {
    }
}
