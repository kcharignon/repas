<?php

namespace Repas\Repas\Application\PlannedMeal;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class PlannedMealHandler
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
    public function __invoke(PlannedMealCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);
        $shoppingList = $this->shoppingListRepository->findOnePlanningByOwner($owner);

        // Si aucune liste active on ne peut pas ajouter de repas
        if (!$shoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $recipe = $this->recipeRepository->findOneById($command->recipeId);

        $shoppingList->addMeal($recipe);

        $this->shoppingListRepository->save($shoppingList);
    }
}
