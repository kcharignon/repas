<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

interface IngredientRepository
{
    /**
     * @throws IngredientException
     */
    public function findOneBySlug(string $slug): Ingredient;

    /**
     * @return Tab<Ingredient>
     */
    public function findByDepartmentAndOwner(Department $department, User $owner): Tab;

    /**
     * @return Tab<Ingredient>
     */
    public function findByOwner(User $owner): Tab;

    public function save(Ingredient $ingredient): void;

    public function cachedByRecipe(string $recipeId): void;

    /**
     * @return Tab<Ingredient>
     */
    public function findAll(): Tab;

    public function delete(Ingredient $ingredient): void;
}
