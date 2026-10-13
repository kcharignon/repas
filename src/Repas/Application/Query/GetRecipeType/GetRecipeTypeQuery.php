<?php

namespace Repas\Repas\Application\Query\GetRecipeType;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetRecipeTypeQuery implements QueryInterface
{
    public function __construct(
        public string $slug,
    ) {
    }
}
