<?php

namespace Repas\Repas\Application\Query\GetShoppingListByMeal;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetShoppingListByMealQuery implements QueryInterface
{
    public function __construct(
        public string $mealId,
    ) {
    }
}
