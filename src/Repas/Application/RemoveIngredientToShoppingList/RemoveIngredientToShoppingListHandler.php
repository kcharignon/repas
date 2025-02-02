<?php

namespace Repas\Repas\Application\RemoveIngredientToShoppingList;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveIngredientToShoppingListHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws UserException
     * @throws IngredientException
     */
    public function __invoke(RemoveIngredientToShoppingListCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);

        $activeShoppingList = $this->shoppingListRepository->findOnePlanningByOwner($owner);
        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $ingredient = $this->ingredientRepository->findOneBySlug($command->ingredientSlug);

        $activeShoppingList->removeIngredient($ingredient);

        $this->shoppingListRepository->save($activeShoppingList);
    }

}
