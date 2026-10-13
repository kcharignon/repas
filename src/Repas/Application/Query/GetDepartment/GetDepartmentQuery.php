<?php

namespace Repas\Repas\Application\Query\GetDepartment;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetDepartmentQuery implements QueryInterface
{
    public function __construct(
        public string $slug,
    ) {
    }
}
