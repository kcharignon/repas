<?php

namespace Repas\Repas\Application\Query\GetDepartment;

use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDepartmentQueryHandler
{
    public function __construct(
        private DepartmentLoader $loader,
    ) {
    }

    /**
     * @throws DepartmentException
     */
    public function __invoke(GetDepartmentQuery $query): DepartmentDTO
    {
        return $this->loader->get($query->slug);
    }
}
