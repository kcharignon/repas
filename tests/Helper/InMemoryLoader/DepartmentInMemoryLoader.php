<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class DepartmentInMemoryLoader implements DepartmentLoader
{
    public int $listCalls = 0;

    /** @var list<string> */
    public array $getCalls = [];

    /**
     * @param list<DepartmentDTO> $departments
     */
    public function __construct(
        private readonly array $departments = [],
        private readonly ?DepartmentDTO $department = null,
    ) {
    }

    public function list(): array
    {
        $this->listCalls++;

        return $this->departments;
    }

    public function get(string $slug): DepartmentDTO
    {
        $this->getCalls[] = $slug;

        return $this->department ?? throw DepartmentException::notFound();
    }
}
