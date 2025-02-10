<?php

namespace Repas\Repas\Application\PlannedMeal;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
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
        private ConversionService $conversionService,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws UserException
     * @throws IngredientException
     */
    public function __invoke(PlannedMealCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);
        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($owner);

        // Si aucune liste active on ne peut pas ajouter de repas
        if (!$shoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $recipe = $this->recipeRepository->findOneById($command->recipeId);

        $shoppingList->addMeal($recipe);

        // On récupere les Ingredients de la recette dans les bonnes proportions
        $rows = $recipe->getRowForServing($owner->getDefaultServing());

        foreach ($rows as $row) {
            // On calcule la quantité dans l'unité d'achat
            $quantity = $this->conversionService->convertRecipeRowToPurchaseUnit($row);
            // On ajoute cette quantité dans la liste d'achat
            $shoppingList->addRow($row->getIngredient(), $quantity);
        }

        $this->shoppingListRepository->save($shoppingList);
    }
}
