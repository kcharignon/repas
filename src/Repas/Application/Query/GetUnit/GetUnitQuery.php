<?php

namespace Repas\Repas\Application\Query\GetUnit;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetUnitQuery implements QueryInterface
{
    public function __construct(
        public string $slug,
    ) {
    }
}
