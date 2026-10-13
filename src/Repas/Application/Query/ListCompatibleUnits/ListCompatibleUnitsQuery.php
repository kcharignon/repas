<?php

namespace Repas\Repas\Application\Query\ListCompatibleUnits;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListCompatibleUnitsQuery implements QueryInterface
{
    public function __construct(
        public string $ingredientSlug,
    ) {
    }
}
