<?php

namespace Repas\Repas\Application\AddRecipeToActiveShoppingList;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AddRecipeToActiveShoppingListHandler
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
    public function __invoke(AddRecipeToActiveShoppingListCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);
        $shoppingList = $this->shoppingListRepository->findOneActiveByOwner($owner);

        // Si aucune liste active on ne peut pas ajouter de repas
        if (!$shoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $recipe = $this->recipeRepository->findOneById($command->recipeId);

        $shoppingList->addMeal($recipe);

        $this->shoppingListRepository->save($shoppingList);
    }
}
