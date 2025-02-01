<?php

namespace Repas\Repas\Application\RemoveMealFromPlan;


readonly class removeMealFromPlanCommand
{

    public function __construct(
        public string $ownerId,
        public string $recipeId,
    ) {
    }
}
