<?php

namespace Repas\Repas\Application\Command\RemoveIngredientToShoppingList;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveIngredientToShoppingListHandler
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
    public function __invoke(RemoveIngredientToShoppingListCommand $command): void
    {
        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $purchaseUnitSlug = $this->ingredientRepository->findPurchaseUnitSlug($command->ingredientSlug);

        $activeShoppingList->removeIngredient($command->ingredientSlug, $purchaseUnitSlug);

        // On ajoute une quantité d'achat
        $activeShoppingList->subtractRow($command->ingredientSlug, 1);

        $this->shoppingListRepository->save($activeShoppingList);
    }

}
