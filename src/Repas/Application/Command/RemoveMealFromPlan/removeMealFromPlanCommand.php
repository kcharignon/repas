<?php

namespace Repas\Repas\Application\Command\RemoveMealFromPlan;


readonly class removeMealFromPlanCommand
{

    public function __construct(
        public string $ownerId,
        public string $recipeId,
    ) {
    }
}
