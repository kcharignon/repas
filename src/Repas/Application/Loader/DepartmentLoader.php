<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;

interface DepartmentLoader
{
    /**
     * Tous les rayons, triés par slug croissant.
     *
     * @return list<DepartmentDTO>
     */
    public function list(): array;
}
