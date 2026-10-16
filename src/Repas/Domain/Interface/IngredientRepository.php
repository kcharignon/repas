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
     * Les ingrédients de ces slugs (chacun une fois), pour les use cases qui en lisent plusieurs d'un coup (lignes d'une recette).
     *
     * @param Tab<string> $slugs
     * @return Tab<Ingredient>
     * @throws IngredientException le premier slug inconnu
     */
    public function findBySlugs(Tab $slugs): Tab;

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

    /**
     * Supprime les ingrédients personnalisés d'un créateur (suppression d'un compte, ADR 0021). Opération en masse :
     * ni contrôle d'usage ni événement par ingrédient, et les agrégats déjà chargés ne sont pas mis à jour.
     */
    public function deleteByCreator(string $creatorId): void;
}
