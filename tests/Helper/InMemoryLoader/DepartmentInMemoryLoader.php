<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class DepartmentInMemoryLoader implements DepartmentLoader
{
    public int $listCalls = 0;

    /**
     * @param list<DepartmentDTO> $departments
     */
    public function __construct(
        private readonly array $departments = [],
    ) {
    }

    public function list(): array
    {
        $this->listCalls++;

        return $this->departments;
    }
}
