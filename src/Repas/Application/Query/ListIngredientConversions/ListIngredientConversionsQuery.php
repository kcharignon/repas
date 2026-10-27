<?php

namespace Repas\Repas\Application\Query\ListIngredientConversions;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListIngredientConversionsQuery implements QueryInterface
{
    public function __construct(
        public string $slug,
        public string $viewerId,
    ) {
    }
}
