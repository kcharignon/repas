<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

interface IngredientRepository
{
    /**
     * @throws IngredientException
     */
    public function getOneBySlug(string $slug): Ingredient;

    /**
     * @return Tab<Department>
     */
    public function getByDepartment(string $department): Tab;

    public function save(Ingredient $ingredient): void;
}
