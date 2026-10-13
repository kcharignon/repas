<?php

namespace Repas\Repas\Application\Query\ListAvailableIngredients;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListAvailableIngredientsQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
