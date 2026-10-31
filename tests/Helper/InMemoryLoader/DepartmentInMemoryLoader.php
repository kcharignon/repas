<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentListItemDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class DepartmentInMemoryLoader implements DepartmentLoader
{
    public int $listCalls = 0;

    /** @var list<string> */
    public array $getCalls = [];

    /**
     * @param list<DepartmentListItemDTO> $departments
     */
    public function __construct(
        private readonly array $departments = [],
        private readonly ?DepartmentDTO $department = null,
    ) {
    }

    /** @var list<string> */
    public array $listUsers = [];

    public function list(string $userId): array
    {
        $this->listCalls++;
        $this->listUsers[] = $userId;

        return $this->departments;
    }

    public function get(string $slug): DepartmentDTO
    {
        $this->getCalls[] = $slug;

        return $this->department ?? throw DepartmentException::notFound();
    }
}
