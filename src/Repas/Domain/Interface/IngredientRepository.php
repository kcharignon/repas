<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;

interface IngredientRepository
{
    /**
     * @throws IngredientException
     */
    public function findOneBySlug(string $slug): Ingredient;

    /**
     * Slug de l'unité d'achat de l'ingrédient : de quoi poser une ligne de liste sans hydrater l'ingrédient, son rayon et ses unités.
     *
     * @throws IngredientException
     */
    public function findPurchaseUnitSlug(string $slug): string;

    /**
     * @return Tab<Ingredient>
     */
    public function findByDepartmentAndOwner(Department $department, string $ownerId): Tab;

    /**
     * @return Tab<Ingredient>
     */
    public function findByOwner(string $ownerId): Tab;

    /**
     * Un ingrédient porte ce slug (le slug dérive du nom et du créateur : deux créations du même nom se télescoperaient).
     */
    public function exists(string $slug): bool;

    public function save(Ingredient $ingredient): void;

    /**
     * @return Tab<Ingredient>
     */
    public function findAll(): Tab;

    public function delete(Ingredient $ingredient): void;
}
