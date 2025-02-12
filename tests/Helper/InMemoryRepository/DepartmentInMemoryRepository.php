<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Shared\Domain\Tool\Tab;

class DepartmentInMemoryRepository extends AbstractInMemoryRepository implements DepartmentRepository
{
    protected static function getClassName(): string
    {
        return Department::class;
    }

    public function save(Department $department): void
    {
        $this->models[$department->getId()] = $department;
    }

    public function findOneBySlug(string $slug): Department
    {
        return $this->models[$slug] ?? throw DepartmentException::notFound();
    }

    public function findBySlugs(Tab $slugs): Tab
    {
        return $this->models->filter(fn(Department $department) => in_array($department->getSlug(), $slugs->toArray()));
    }

    public function findAll(): Tab
    {
        return $this->models;
    }
}
