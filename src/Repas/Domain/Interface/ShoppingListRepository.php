<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

interface ShoppingListRepository
{
    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwner(User $owner): Tab;

    public function findOneById(string $id): ShoppingList;

    public function findOneActivateByOwner(User $owner): ?ShoppingList;

    public function save(ShoppingList $shoppingList): void;

    public function delete(ShoppingList $shoppingList): void;

    /**
     * @return Tab<ShoppingList>
     */
    public function findByOwnerAndStatus(User $owner, ShoppingListStatus $status): Tab;

    public function findOneByMealId(string $mealId): ShoppingList;
}
