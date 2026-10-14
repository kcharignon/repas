<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Tool\Tab;

class ShoppingListInMemoryRepository extends AbstractInMemoryRepository implements ShoppingListRepository
{
    protected static function getClassName(): string
    {
        return ShoppingList::class;
    }

    public function findByOwner(string $ownerId): Tab
    {
        return $this->models->filter(fn(ShoppingList $sl) => $sl->getOwnerId() === $ownerId);
    }

    /**
     * @throws ShoppingListException
     */
    public function findOneById(string $id): ShoppingList
    {
        return $this->models[$id] ?? throw ShoppingListException::shoppingListNotFound($id);
    }

    public function findOneActivateByOwner(string $ownerId): ?ShoppingList
    {
        return $this->models->find(fn(ShoppingList $sl) => $sl->getOwnerId() === $ownerId && $sl->isActivate());
    }

    public function hasActiveShoppingList(string $ownerId): bool
    {
        foreach ($this->models as $shoppingList) {
            /** @var ShoppingList $shoppingList */
            if ($shoppingList->getOwnerId() === $ownerId && $shoppingList->isActivate()) {
                return true;
            }
        }

        return false;
    }

    public function save(ShoppingList $shoppingList): void
    {
        $this->models[$shoppingList->getId()] = $shoppingList;
    }

    public function delete(ShoppingList $shoppingList): void
    {
        unset($this->models[$shoppingList->getId()]);
    }

    public function findIdsByOwnerAndStatus(string $ownerId, ShoppingListStatus $status): array
    {
        $ids = [];
        /** @var ShoppingList $shoppingList */
        foreach ($this->models as $shoppingList) {
            if ($shoppingList->getOwnerId() === $ownerId && $shoppingList->getStatus() === $status) {
                $ids[] = $shoppingList->getId();
            }
        }

        return $ids;
    }

    public function findOneByMealId(string $mealId): ShoppingList
    {
        return $this->models->find(fn(ShoppingList $sl) => $sl->getMeals()->find(fn(Meal $meal) => $meal->getId() === $mealId));
    }

    public function isIngredientUsed(string $ingredientSlug): bool
    {
        /** @var ShoppingList $shoppingList */
        foreach ($this->models as $shoppingList) {
            foreach ($shoppingList->getIngredients() as $ingredient) {
                if ($ingredient->getIngredientSlug() === $ingredientSlug) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isRecipePlanned(string $recipeId): bool
    {
        /** @var ShoppingList $shoppingList */
        foreach ($this->models as $shoppingList) {
            foreach ($shoppingList->getMeals() as $meal) {
                if ($meal->getRecipeId() === $recipeId) {
                    return true;
                }
            }
        }

        return false;
    }
}
