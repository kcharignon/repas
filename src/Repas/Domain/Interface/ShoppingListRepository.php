<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

interface ShoppingListRepository
{
    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwner(User $owner): Tab;

    public function findOneById(string $id): ShoppingList;

    public function findOnePlanningByOwner(User $owner): ?ShoppingList;

    public function save(ShoppingList $shoppingList): void;

    public function delete(ShoppingList $shoppingList): void;
}
