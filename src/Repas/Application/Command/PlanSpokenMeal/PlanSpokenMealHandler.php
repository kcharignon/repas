<?php

namespace Repas\Repas\Application\Command\PlanSpokenMeal;

use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Dto\PlannedSpokenMeal;
use Repas\Shared\Application\Interface\UserDefaultServing;
use Repas\Shared\Domain\Exception\SpokenMealFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Planifie un repas dit à la voix : dans la liste active, sinon dans une liste neuve (la liste envoyée au drive n'est jamais modifiée). Comme
 * `PlannedMealHandler` (le repas et ses ingrédients dans les bonnes proportions : il est réutilisé tel quel), mais :
 *
 * - la recette doit être à l'utilisateur (celle d'un autre, comme une recette inconnue, est `404` avant toute écriture, y compris la création de la
 *   liste) ;
 * - le nombre de personnes est celui du compte (`UserDefaultServing`) sauf s'il a été dit (1 à 99) ;
 * - **une recette déjà dans la liste n'y est jamais mise deux fois** (le domaine le refuse) : rien n'est écrit, aucune liste n'est créée, et le résultat
 *   dit le nombre de personnes pour lequel elle est prévue (`PlannedSpokenMeal::$alreadyPlanned`) ;
 * - sans liste active, une liste est créée UNE fois (elle met en pause la liste active qui existerait) et le résultat dit si une liste envoyée au drive
 *   existait avant.
 *
 * Un ingrédient de la recette sans conversion vers son unité d'achat fait échouer toute la commande (le bus est transactionnel : rien n'est écrit),
 * comme dans l'application, mais par une `SpokenMealFailure` que le webhook dit clairement (« Je ne peux pas calculer les quantités de … »).
 */
#[AsMessageHandler]
readonly class PlanSpokenMealHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private CreateShoppingListHandler $createShoppingListHandler,
        private PlannedMealHandler $plannedMealHandler,
        private UserDefaultServing $defaultServing,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws RecipeException
     * @throws IngredientException
     */
    public function __invoke(PlanSpokenMealCommand $command): PlannedSpokenMeal
    {
        if ($command->servings !== null && ($command->servings < PlanSpokenMealCommand::MIN_SERVINGS || $command->servings > PlanSpokenMealCommand::MAX_SERVINGS)) {
            throw ShoppingListException::invalidSpokenServings($command->servings);
        }

        // La recette d'un autre ou inconnue est refusée (404) avant toute écriture ; la commande vocale en tire une phrase claire (`SpokenMealFailure`)
        try {
            $recipe = $this->recipeRepository->findOneById($command->recipeId);
        } catch (RecipeException) {
            throw SpokenMealFailure::recipeGone();
        }
        if ($recipe->getAuthorId() !== $command->ownerId) {
            throw SpokenMealFailure::recipeGone();
        }

        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        if ($shoppingList instanceof ShoppingList) {
            $meal = $shoppingList->getMeals()->find(static fn(Meal $meal): bool => $meal->hasRecipe($recipe));
            if ($meal instanceof Meal) {
                return new PlannedSpokenMeal($recipe->getName(), $meal->getServing(), alreadyPlanned: true, listCreated: false, previousListSent: false);
            }
        }

        $servings = $command->servings ?? $this->defaultServing->of($command->ownerId);

        $listCreated = !$shoppingList instanceof ShoppingList;
        $previousListSent = false;
        if ($listCreated) {
            $previousListSent = $this->shoppingListRepository->hasSentShoppingList($command->ownerId);
            ($this->createShoppingListHandler)(new CreateShoppingListCommand($command->newShoppingListId, $command->ownerId));
        }

        // Un ingrédient sans conversion vers son unité d'achat : les quantités ne se calculent pas. Phrase claire, et toujours rien d'écrit (le bus annule tout)
        try {
            ($this->plannedMealHandler)(new PlannedMealCommand($command->ownerId, $command->recipeId, $servings));
        } catch (IngredientException $exception) {
            throw $exception->isMissingConversion() ? SpokenMealFailure::missingConversion($recipe->getName(), $exception->conversionIngredientName()) : $exception;
        }

        return new PlannedSpokenMeal($recipe->getName(), $servings, alreadyPlanned: false, listCreated: $listCreated, previousListSent: $previousListSent);
    }
}
