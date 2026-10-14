<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
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
     * @return Tab<ShoppingList>
     */
    public function findByIngredient(Ingredient $ingredient): Tab;

    /**
     * @return Tab<ShoppingList>
     */
    public function findByRecipe(Recipe $recipe): Tab;
}
