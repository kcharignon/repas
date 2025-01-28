<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Department;

interface DepartmentRepository
{
    public function findBySlug(string $slug): Department;

    public function save(Department $department): void;
}
