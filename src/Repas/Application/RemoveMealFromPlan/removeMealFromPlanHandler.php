<?php

namespace Repas\Repas\Application\RemoveMealFromPlan;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
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
        private ConversionService $conversionService,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws UserException
     * @throws IngredientException
     */
    public function __invoke(removeMealFromPlanCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);

        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($owner);

        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $recipe = $this->recipeRepository->findOneById($command->recipeId);
        $meal = $activeShoppingList->getMeals()->find(fn(Meal $meal) => $meal->hasRecipe($recipe));

        // Si meal n'est pas trouvé le repas est déjà supprimé de la liste
        if (!$meal instanceof Meal) {
            return;
        }

        // On récupere les Ingredients de la recette dans les bonnes proportions
        $rows = $recipe->getRowForServing($meal->getServing());

        foreach ($rows as $row) {
            // On calcule la quantité dans l'unité d'achat
            $quantity = $this->conversionService->convertRecipeRowToPurchaseUnit($row);
            // On enleve cette quantité dans la liste d'achat
            $activeShoppingList->subtractRow($row->getIngredient(), $quantity);
        }

        $activeShoppingList->removeMeal($recipe);

        $this->shoppingListRepository->save($activeShoppingList);
    }
}
