<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

class ShoppingListInMemoryRepository extends AbstractInMemoryRepository implements ShoppingListRepository
{
    protected static function getClassName(): string
    {
        return ShoppingList::class;
    }

    public function findByOwner(User $owner): Tab
    {
        return $this->models->filter(fn(ShoppingList $sl) => $sl->getOwner()->isEqual($owner));
    }

    /**
     * @throws ShoppingListException
     */
    public function findOneById(string $id): ShoppingList
    {
        return $this->models[$id] ?? throw ShoppingListException::shoppingListNotFound();
    }

    public function findOneActivateByOwner(User $owner): ?ShoppingList
    {
        return $this->models->find(fn(ShoppingList $sl) => $sl->getOwner()->isEqual($owner) && $sl->isActivate());
    }

    public function save(ShoppingList $shoppingList): void
    {
        $this->models[$shoppingList->getId()] = $shoppingList;
    }

    public function delete(ShoppingList $shoppingList): void
    {
        unset($this->models[$shoppingList->getId()]);
    }

    public function findByOwnerAndStatus(User $owner, ShoppingListStatus $status): Tab
    {
        return $this->models->find(fn(ShoppingList $sl) => $sl->getOwner()->isEqual($owner) && $sl->getStatus()->value === $status->value);
    }

    public function findOneByMealId(string $mealId): ShoppingList
    {
        return $this->models->find(fn(ShoppingList $sl) => $sl->getMeals()->find($mealId));
    }

}
