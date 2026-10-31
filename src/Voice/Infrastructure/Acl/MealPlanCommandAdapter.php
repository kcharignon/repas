<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\MealPlanCommandOHS;
use Repas\Repas\Domain\Exception\MealPlanningException;
use Repas\Voice\Application\Port\MealPlanCommandPort;
use Repas\Voice\Domain\Exception\SpokenMealFailure;
use Repas\Voice\Domain\Model\ChangedMealServings;
use Repas\Voice\Domain\Model\PlannedSpokenMeal;
use Repas\Voice\Domain\Model\RemovedSpokenMeal;

/**
 * Couche anticorruption (ADR 0035) : ce que la liste de courses de `Repas` a fait d'un repas (planifié, changé, retiré) devient ce dont la réponse
 * vocale a besoin, et un échec connu de `Repas` (recette disparue, conversion manquante) devient celui que le webhook sait dire (`SpokenMealFailure`).
 * Rien n'est décidé ici ; toute autre erreur de `Repas` n'est pas attrapée (la commande entière échoue, rien n'est écrit).
 */
final readonly class MealPlanCommandAdapter implements MealPlanCommandPort
{
    public function __construct(
        private MealPlanCommandOHS $meals,
    ) {
    }

    public function plan(string $userId, string $recipeId, ?int $servings = null): PlannedSpokenMeal
    {
        try {
            $planned = $this->meals->plan($userId, $recipeId, $servings);
        } catch (MealPlanningException $failure) {
            throw self::failure($failure);
        }

        return new PlannedSpokenMeal($planned->recipeName, $planned->servings, $planned->alreadyPlanned, $planned->listCreated, $planned->previousListSent, $planned->typedListNames, $planned->inMainList);
    }

    public function changeServings(string $userId, string $recipeId, int $servings): ChangedMealServings
    {
        try {
            $changed = $this->meals->changeServings($userId, $recipeId, $servings);
        } catch (MealPlanningException $failure) {
            throw self::failure($failure);
        }

        return match (true) {
            $changed->listSent => ChangedMealServings::listSent($changed->recipeName),
            $changed->found => ChangedMealServings::changed($changed->recipeName, $changed->servings),
            default => ChangedMealServings::notPlanned($changed->recipeName),
        };
    }

    public function removeMeal(string $userId, string $recipeId, ?int $servings = null): RemovedSpokenMeal
    {
        try {
            $removed = $this->meals->removeMeal($userId, $recipeId, $servings);
        } catch (MealPlanningException $failure) {
            throw self::failure($failure);
        }

        return match (true) {
            $removed->listSent => RemovedSpokenMeal::listSent($removed->recipeName),
            !$removed->found => RemovedSpokenMeal::notPlanned($removed->recipeName),
            $removed->removedAll => RemovedSpokenMeal::removed($removed->recipeName),
            default => RemovedSpokenMeal::lowered($removed->recipeName, $removed->servings),
        };
    }

    private static function failure(MealPlanningException $failure): SpokenMealFailure
    {
        return match ($failure->reason) {
            MealPlanningException::MISSING_CONVERSION => SpokenMealFailure::missingConversion($failure->recipeName ?? '', $failure->ingredientName, $failure),
            default => SpokenMealFailure::recipeGone($failure),
        };
    }
}
