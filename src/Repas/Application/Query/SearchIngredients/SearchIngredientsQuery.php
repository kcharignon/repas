<?php

namespace Repas\Repas\Application\Query\SearchIngredients;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class SearchIngredientsQuery implements QueryInterface
{
    public function __construct(
        public string $search,
        public string $viewerId,
        public bool $viewerIsAdmin,
    ) {
    }
}
