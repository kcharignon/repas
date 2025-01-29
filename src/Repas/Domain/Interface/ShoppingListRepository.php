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
    public function getByOwner(User $owner): Tab;

    public function getOneById(string $id): ShoppingList;

    public function getOneActiveByOwner(User $owner): ?ShoppingList;

    public function save(ShoppingList $shoppingList): void;
}
