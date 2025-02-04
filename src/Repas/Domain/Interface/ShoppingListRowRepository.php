<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingListRow;

interface ShoppingListRowRepository
{
    public function findOneById(string $id): ShoppingListRow;

    public function save(ShoppingListRow $shoppingListRow): void;
}
