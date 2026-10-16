<?php

namespace Repas\Repas\Application\Command\AddIngredientToShoppingList;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AddIngredientToShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     */
    public function __invoke(AddIngredientToShoppingListCommand $command): void
    {
        $shoppingListActive = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        if (!$shoppingListActive instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }
        $shoppingListActive->assertVersion($command->expectedVersion);

        $purchaseUnitSlug = $this->ingredientRepository->findPurchaseUnitSlugUsableBy($command->ingredientSlug, $command->ownerId);
        $shoppingListActive->addIngredient($command->ingredientSlug, $purchaseUnitSlug);

        $shoppingListActive->addRow($command->ingredientSlug, $purchaseUnitSlug, 1);

        $this->shoppingListRepository->save($shoppingListActive);
    }
}
