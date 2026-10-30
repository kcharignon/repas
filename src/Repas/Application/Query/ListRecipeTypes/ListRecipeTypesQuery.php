<?php

namespace Repas\Repas\Application\Query\ListRecipeTypes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListRecipeTypesQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
