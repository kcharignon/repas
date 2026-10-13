<?php

namespace Repas\Repas\Application\Query\ListDepartments;

use Repas\Repas\Application\Loader\DepartmentLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListDepartmentsQueryHandler
{
    public function __construct(
        private DepartmentLoader $departmentLoader,
    ) {
    }

    /**
     * @return list<DepartmentDTO>
     */
    public function __invoke(ListDepartmentsQuery $query): array
    {
        return $this->departmentLoader->list();
    }
}
