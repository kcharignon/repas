<?php

namespace Repas\Repas\Application\Query\ListIngredientsByDepartment;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListIngredientsByDepartmentQuery implements QueryInterface
{
    public function __construct(
        public string $departmentSlug,
        public string $viewerId,
    ) {
    }
}
