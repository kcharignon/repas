<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Shared\Domain\Tool\Tab;

class ShoppingListRowInMemoryRepository extends AbstractInMemoryRepository implements ShoppingListRowRepository
{
    protected static function getClassName(): string
    {
        return ShoppingListRow::class;
    }

    public function findOneById(string $id): ShoppingListRow
    {
        return $this->models[$id] ?? throw ShoppingListRowException::notFound($id);
    }

    public function save(ShoppingListRow $shoppingListRow): void
    {
        $this->models[$shoppingListRow->getId()] = $shoppingListRow;
    }

    public function findByShoppingListId(string $shoppingListId): Tab
    {
        return $this->models->find(fn(ShoppingListRow $row) => $row->getShoppingListId() === $shoppingListId);
    }

    public function deleteByShoppingListIdExceptIds(string $shoppingListId, Tab $ids): void
    {
        $this->models = $this->models->filter(fn(ShoppingListRow $row) => $row->getShoppingListId() === $shoppingListId && !$ids->find(fn (string $id) => $row->getId() === $id));
    }

    public function deleteByShoppingListId(string $shoppingListId): void
    {
        $this->models = $this->models->filter(fn(ShoppingListRow $row) => $row->getShoppingListId() === $shoppingListId);
    }
}
