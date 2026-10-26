<?php

namespace Repas\Repas\Application\Command\ChangeSpokenMealServings;

use Repas\Repas\Application\Command\PlanSpokenMeal\PlanSpokenMealCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Dto\ChangedMealServings;
use Repas\Shared\Domain\Exception\SpokenMealFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Change le nombre de personnes d'un repas déjà dans la liste active, dit à la voix : « Hachis parmentier est déjà prévu pour 4 personnes. » puis
 * « 6 ». Le nouveau total remplace l'ancien (jamais une addition) ; les lignes des ingrédients sont ajustées de la différence par la logique
 * d'`UpdateServingMealHandler`, réutilisée telle quelle (une liste envoyée au drive n'est pas la liste active : jamais modifiée).
 *
 * La recette doit être à l'utilisateur (`404` sinon). Pas de liste active, ou la recette n'y est plus (elle a pu être retirée depuis la question) :
 * rien n'est écrit, le résultat dit `notPlanned`. Le même total qu'avant n'écrit rien non plus.
 */
#[AsMessageHandler]
readonly class ChangeSpokenMealServingsHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private UpdateServingMealHandler $updateServingMealHandler,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws RecipeException
     */
    public function __invoke(ChangeSpokenMealServingsCommand $command): ChangedMealServings
    {
        if ($command->servings < PlanSpokenMealCommand::MIN_SERVINGS || $command->servings > PlanSpokenMealCommand::MAX_SERVINGS) {
            throw ShoppingListException::invalidSpokenServings($command->servings);
        }

        try {
            $recipe = $this->recipeRepository->findOneById($command->recipeId);
        } catch (RecipeException) {
            throw SpokenMealFailure::recipeGone();
        }
        if ($recipe->getAuthorId() !== $command->ownerId) {
            throw SpokenMealFailure::recipeGone();
        }

        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        $meal = $shoppingList instanceof ShoppingList ? $shoppingList->getMeals()->find(static fn(Meal $meal): bool => $meal->hasRecipe($recipe)) : null;
        if (!$meal instanceof Meal) {
            return ChangedMealServings::notPlanned($recipe->getName());
        }

        if ($meal->getServing() !== $command->servings) {
            try {
                ($this->updateServingMealHandler)(new UpdateServingMealCommand($meal->getId(), $command->servings));
            } catch (IngredientException $exception) {
                throw $exception->isMissingConversion() ? SpokenMealFailure::missingConversion($recipe->getName()) : $exception;
            }
        }

        return ChangedMealServings::changed($recipe->getName(), $command->servings);
    }
}
