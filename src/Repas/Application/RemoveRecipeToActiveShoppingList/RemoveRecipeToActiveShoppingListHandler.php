<?php

namespace Repas\Repas\Application\RemoveRecipeToActiveShoppingList;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveRecipeToActiveShoppingListHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws UserException
     */
    public function __invoke(RemoveRecipeToActiveShoppingListCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);

        $activeShoppingList = $this->shoppingListRepository->findOnePlanningByOwner($owner);
        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $recipe = $this->recipeRepository->findOneById($command->recipeId);

        $activeShoppingList->removeMeal($recipe);
        $this->shoppingListRepository->save($activeShoppingList);

    }
}
