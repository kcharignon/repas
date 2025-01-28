<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\Department;

interface DepartmentRepository
{
    public function save(Department $department): void;

    /**
     * @throws DepartmentException
     */
    public function findBySlug(string $slug): Department;
}
