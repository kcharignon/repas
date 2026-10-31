<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;

/**
 * Un repas d'un groupe de planification (ADR 0036 décision 9) avec la liste qui le porte.
 */
final readonly class GroupedMeal
{
    public function __construct(
        public ShoppingList $shoppingList,
        public Meal $meal,
    ) {
    }
}
