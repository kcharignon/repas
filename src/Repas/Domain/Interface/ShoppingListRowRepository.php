<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListRow as ShoppingListRowModel;
use Repas\Shared\Domain\Tool\Tab;

interface ShoppingListRowRepository
{
    public function findOneById(string $id): ShoppingListRow;

    public function save(ShoppingListRow $shoppingListRow): void;

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
