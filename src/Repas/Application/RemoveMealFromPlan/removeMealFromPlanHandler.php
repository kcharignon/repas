<?php

namespace Repas\Repas\Application\RemoveMealFromPlan;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class removeMealFromPlanHandler
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
    public function __invoke(removeMealFromPlanCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);

        $activeShoppingList = $this->shoppingListRepository->findOnePlanningByOwner($owner);

        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $recipe = $this->recipeRepository->findOneById($command->recipeId);

        $activeShoppingList->removeMeal($recipe);
        dump($activeShoppingList);
        $this->shoppingListRepository->save($activeShoppingList);

        dump($this->shoppingListRepository->findOneById($activeShoppingList->getId()));
    }
}
