<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use DateTimeImmutable;
use Repas\Repas\Domain\Model\DriveLineAttempt;
use Repas\Repas\Domain\Model\DriveLineOutcome;
use Repas\Repas\Domain\Model\RowCheckSource;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Shared\Domain\Tool\Tab;

/**
 * @extends AbstractInMemoryRepository<ShoppingListRow>
 */
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

    public function allRowsChecked(string $shoppingListId): bool
    {
        /** @var ShoppingListRow $row */
        foreach ($this->models as $row) {
            if ($row->getShoppingListId() === $shoppingListId && !$row->isChecked()) {
                return false;
            }
        }

        return true;
    }

    /** @var array<string, DriveLineAttempt> la dernière issue du drive de chaque ligne, par « liste/ingrédient » */
    public array $driveAttempts = [];

    public function recordDrivePassage(string $shoppingListId, array $attempts, DateTimeImmutable $at): void
    {
        foreach ($attempts as $attempt) {
            /** @var ShoppingListRow $row */
            foreach ($this->models->toArray() as $row) {
                if ($row->getShoppingListId() !== $shoppingListId || $row->getIngredientSlug() !== $attempt->ingredientSlug) {
                    continue;
                }
                $this->driveAttempts[$shoppingListId . '/' . $attempt->ingredientSlug] = $attempt;
                // La ligne mise au panier est cochée par le drive ; une ligne déjà cochée garde son origine (comme l'instruction SQL)
                if ($attempt->outcome === DriveLineOutcome::IN_CART && !$row->isChecked()) {
                    $this->models[$row->getId()] = ShoppingListRow::load([
                        'id' => $row->getId(), 'shopping_list_id' => $row->getShoppingListId(), 'ingredient_slug' => $row->getIngredientSlug(),
                        'quantity' => $row->getQuantity(), 'unit_slug' => $row->getUnitSlug(), 'checked' => true,
                        'checked_by' => RowCheckSource::DRIVE, 'checked_at' => $at,
                    ]);
                }
            }
        }
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
