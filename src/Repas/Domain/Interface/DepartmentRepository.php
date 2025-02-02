<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\Department;
use Repas\Shared\Domain\Tool\Tab;

interface DepartmentRepository
{
    public function save(Department $department): void;

    /**
     * @throws DepartmentException
     */
    public function findOneBySlug(string $slug): Department;

    /**
     * @param Tab<string> $slugs
     * @return Tab<Department>
     */
    public function findBySlugs(Tab $slugs): Tab;

    /**
     * @return Tab<Department>
     */
    public function findAll(): Tab;
}
