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
     * Comme `findPurchaseUnitSlug()`, pour poser l'ingrédient dans la liste d'un utilisateur : un ingrédient est utilisable par
     * celui qui le voit (ADR 0028, amendement) : le sien. L'ingrédient d'un autre est traité comme inconnu, administrateur compris.
     *
     * @throws IngredientException
     */
    public function findPurchaseUnitSlugUsableBy(string $slug, string $userId): string;

    /**
     * Les ingrédients que cet utilisateur voit dans ce rayon : les siens (ADR 0028, amendement).
     *
     * @return Tab<Ingredient>
     */
    public function findByDepartmentAndOwner(Department $department, string $ownerId): Tab;

    /**
     * Les ingrédients que cet utilisateur voit : les siens (ADR 0028, amendement).
     *
     * @return Tab<Ingredient>
     */
    public function findByOwner(string $ownerId): Tab;

    /**
     * Un ingrédient porte ce slug (le slug dérive du nom et du créateur : `IngredientSlugPolicy` en cherche un libre pour chaque création ou
     * copie, jamais celui d'un ingrédient existant).
     */
    public function exists(string $slug): bool;

    /**
     * Ce créateur a-t-il déjà un ingrédient de ce nom normalisé (`slugged_name`) dans ce rayon ? C'est la règle d'unicité d'un ingrédient
     * (ADR 0028, amendement du 2026-10-30, ING-016) : un propriétaire ne peut pas avoir deux ingrédients de même nom DANS LE MÊME RAYON, mais
     * le même nom dans deux rayons est permis. `$exceptSlug` est un ingrédient qu'on ne compte pas : celui qu'on modifie. Une seule requête.
     * Seules la création et la modification d'un ingrédient l'appellent : la copie d'une recette, l'amorçage d'un compte et la fusion ne sont
     * pas des créations (un doublon se voit et se fusionne).
     */
    public function creatorHasNameInDepartment(string $creatorId, string $sluggedName, string $departmentSlug, ?string $exceptSlug = null): bool;

    public function save(Ingredient $ingredient): void;

    /**
     * Le créateur a-t-il au moins `$count` ingrédients ? La lecture s'arrête au `$count`-ième : jamais un décompte complet (plafond
     * d'ingrédients créés, `Ingredient::MAX_PER_CREATOR`).
     */
    public function creatorHasAtLeast(string $creatorId, int $count): bool;

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
