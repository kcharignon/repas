<?php

namespace Repas\Repas\Application\Command\RemoveSpokenMeal;

use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanHandler;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\MealRemovalResult;
use Repas\Repas\Domain\Exception\MealPlanningException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire un repas de la liste active, ou baisse son nombre de personnes, dit à la voix (« enlève le repas hachis parmentier », « … pour 2 personnes »).
 * Les lignes des ingrédients diminuent de ce que le repas apportait par la logique des handlers existants, réutilisée telle quelle :
 *
 * - sans nombre de personnes, ou en enlevant autant de personnes que le repas en compte (ou plus) : `removeMealFromPlanHandler`, le repas quitte la liste ;
 * - en enlevant moins : `UpdateServingMealHandler` fixe le nouveau total (le nombre de personnes n'est jamais nul ni négatif).
 *
 * La recette doit être à l'utilisateur (`404` sinon, rien n'est écrit). Pas de liste active (une liste envoyée au drive n'en est pas une : jamais modifiée),
 * ou la recette n'y est plus : rien n'est écrit, le résultat dit `notPlanned`. Une panne annule toute la commande (le bus est transactionnel).
 */
#[AsMessageHandler]
readonly class RemoveSpokenMealHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private removeMealFromPlanHandler $removeMealFromPlanHandler,
        private UpdateServingMealHandler $updateServingMealHandler,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws RecipeException
     */
    public function __invoke(RemoveSpokenMealCommand $command): MealRemovalResult
    {
        if ($command->servings !== null && ($command->servings < RemoveSpokenMealCommand::MIN_SERVINGS || $command->servings > RemoveSpokenMealCommand::MAX_SERVINGS)) {
            throw ShoppingListException::invalidSpokenServings($command->servings);
        }

        try {
            $recipe = $this->recipeRepository->findOneById($command->recipeId);
        } catch (RecipeException) {
            throw MealPlanningException::recipeGone();
        }
        if ($recipe->getAuthorId() !== $command->ownerId) {
            throw MealPlanningException::recipeGone();
        }

        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        $meal = $shoppingList instanceof ShoppingList ? $shoppingList->getMeals()->find(static fn(Meal $meal): bool => $meal->hasRecipe($recipe)) : null;
        if (!$meal instanceof Meal) {
            return MealRemovalResult::notPlanned($recipe->getName());
        }

        try {
            if ($command->servings === null || $command->servings >= $meal->getServing()) {
                ($this->removeMealFromPlanHandler)(new removeMealFromPlanCommand($command->ownerId, $command->recipeId));

                return MealRemovalResult::removed($recipe->getName());
            }

            $remaining = $meal->getServing() - $command->servings;
            ($this->updateServingMealHandler)(new UpdateServingMealCommand($meal->getId(), $remaining));
        } catch (IngredientException $exception) {
            throw $exception->isMissingConversion() ? MealPlanningException::missingConversion($recipe->getName(), $exception->conversionIngredientName()) : $exception;
        }

        return MealRemovalResult::lowered($recipe->getName(), $remaining);
    }
}
