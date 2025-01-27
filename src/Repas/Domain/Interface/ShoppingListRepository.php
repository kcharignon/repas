<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Model\User;

interface ShoppingListRepository
{
    /**
     * @return array<ShoppingList>
     */
    public function findByOwner(User $owner): array;

    public function findById(string $id): ShoppingList;

    public function save(ShoppingList $shoppingList): void;
}
