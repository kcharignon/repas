<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

interface IngredientRepository
{
    public function findBySlug(string $slug): Ingredient;

    /**
     * @return Tab<Department>
     */
    public function findByDepartment(string $department): Tab;

    public function save(Ingredient $ingredient): void;
}
