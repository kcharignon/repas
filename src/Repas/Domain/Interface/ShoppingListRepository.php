<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;

interface ShoppingListRepository
{
    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwner(string $ownerId): Tab;

    public function findOneById(string $id): ShoppingList;

    public function findOneActivateByOwner(string $ownerId): ?ShoppingList;

    /**
     * L'utilisateur a-t-il une liste active ? Sans hydrater l'agrégat ni importer le domaine du contexte User.
     */
    public function hasActiveShoppingList(string $ownerId): bool;

    /**
     * La liste est-elle envoyée au drive (`SENT`, ADR 0023) ? Une requête, sans hydrater l'agrégat : cocher et décocher ne
     * chargent qu'une ligne. Une liste inconnue n'est pas envoyée.
     */
    public function isSent(string $shoppingListId): bool;

    public function save(ShoppingList $shoppingList): void;

    public function delete(ShoppingList $shoppingList): void;

    /**
     * Identifiants seulement : de quoi agir sur ces listes sans les hydrater.
     *
     * @return list<string>
     */
    public function findIdsByOwnerAndStatus(string $ownerId, ShoppingListStatus $status): array;

    public function findOneByMealId(string $mealId): ShoppingList;

    /**
     * Une liste de courses contient-elle cet ingrédient ? (existence seulement : aucun agrégat n'est hydraté)
     */
    public function isIngredientUsed(string $ingredientSlug): bool;

    /**
     * Un repas d'une liste de courses est-il planifié avec cette recette ?
     */
    public function isRecipePlanned(string $recipeId): bool;

    /**
     * Supprime toutes les listes d'un propriétaire, avec leurs repas, ingrédients et lignes (suppression d'un compte,
     * ADR 0021). Opération en masse : les agrégats déjà chargés ne sont pas mis à jour.
     */
    public function deleteByOwner(string $ownerId): void;

    /**
     * Retire des listes de courses, quel qu'en soit le propriétaire, les repas planifiés avec ces recettes. Les quantités
     * des lignes ne sont pas recalculées (suppression d'un compte, ADR 0021).
     *
     * @param list<string> $recipeIds
     */
    public function removeMealsOfRecipes(array $recipeIds): void;
}
