<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;

interface ShoppingListRowRepository
{
    public function findOneById(string $id): ShoppingListRow;

    public function save(ShoppingListRow $shoppingListRow): void;

    /**
     * Vrai quand plus aucune ligne de la liste n'est à cocher (une liste sans ligne l'est aussi).
     */
    public function allRowsChecked(string $shoppingListId): bool;

    /**
     * Coche, en UNE instruction, les lignes de cette liste dont l'ingrédient est dans `$ingredientSlugs` (DRV-745 : ce que le drive a mis au
     * panier). Ne décoche jamais rien, ne touche ni la version de la liste ni ses événements ; un slug qui n'est pas dans la liste est ignoré,
     * une ligne déjà cochée reste telle (idempotent). Aucun contrôle de droit ni de statut : c'est celui du cas d'usage.
     *
     * @param list<string> $ingredientSlugs
     */
    public function checkByIngredientSlugs(string $shoppingListId, array $ingredientSlugs): void;

    /**
     * @return Tab<ShoppingListRowModel>
     */
    public function findByShoppingListId(string $shoppingListId): Tab;

    /**
     * @param Tab<string> $ids
     */
    public function deleteByShoppingListIdExceptIds(string $shoppingListId, Tab $ids): void;

    public function deleteByShoppingListId(string $shoppingListId): void;

}
