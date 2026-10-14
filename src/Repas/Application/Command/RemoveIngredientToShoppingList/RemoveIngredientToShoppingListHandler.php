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

        $ingredient = $this->ingredientRepository->findOneBySlug($command->ingredientSlug);

        $activeShoppingList->removeIngredient($ingredient);

        // On ajoute une quantité d'achat
        $activeShoppingList->subtractRow($ingredient, 1);

        $this->shoppingListRepository->save($activeShoppingList);
    }

}
