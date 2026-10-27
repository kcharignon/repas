<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;

interface RecipeRepository
{
    public function findOneById(string $id): Recipe;

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthor(string $authorId): Tab;

    /**
     * @return Tab<Recipe>
     */
    public function findBy(array $criteria, ?array $orderBy = null): Tab;

    public function save(Recipe $recipe): void;

    /**
     * L'auteur a-t-il au moins `$count` recettes ? La lecture s'arrête à la `$count`-ième : jamais un décompte complet (plafond de recettes,
     * `Recipe::MAX_PER_AUTHOR`).
     */
    public function authorHasAtLeast(string $authorId, int $count): bool;

    /**
     * Une recette utilise-t-elle cet ingrédient ? (existence seulement : aucun agrégat n'est hydraté)
     */
    public function isIngredientUsed(string $ingredientSlug): bool;

    public function delete(Recipe $recipe): void;

    /**
     * Identifiants seulement : de quoi agir sur les recettes d'un auteur sans les hydrater.
     *
     * @return list<string>
     */
    public function findIdsByAuthor(string $authorId): array;

    /**
     * Les copies de ces recettes deviennent des recettes originales (`originalId` à `null`, version augmentée), comme si
     * leur auteur les avait écrites : l'original disparaît (suppression d'un compte, ADR 0021). Opération en masse : les
     * agrégats déjà chargés ne sont pas mis à jour.
     *
     * @param list<string> $recipeIds
     */
    public function makeCopiesOriginal(array $recipeIds): void;

    /**
     * Supprime toutes les recettes d'un auteur et leurs lignes (suppression d'un compte, ADR 0021). Opération en masse :
     * ni contrôle de version ni événement par recette, et les agrégats déjà chargés ne sont pas mis à jour.
     */
    public function deleteByAuthor(string $authorId): void;
}
