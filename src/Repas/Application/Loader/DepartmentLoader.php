<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Domain\Exception\DepartmentException;

interface DepartmentLoader
{
    /**
     * Tous les rayons, triés par slug croissant.
     *
     * @return list<DepartmentDTO>
     */
    public function list(): array;

    /**
     * @throws DepartmentException
     */
    public function get(string $slug): DepartmentDTO;
}
