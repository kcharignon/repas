<?php

namespace Repas\Repas\Application\Query\GetRecipe;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetRecipeQuery implements QueryInterface
{
    public function __construct(
        public string $recipeId,
    ) {
    }
}
