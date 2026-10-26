<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Dto\ChangedMealServings;
use Repas\Shared\Application\Dto\PlannedSpokenMeal;
use Repas\Shared\Application\Interface\VoiceMealPlanner;
use Throwable;

/**
 * Double du port `VoiceMealPlanner` : note les repas planifiés et les nombres de personnes changés, renvoie le résultat programmé (repas planifié, pas
 * encore dans la liste, par défaut) ou lève l'exception programmée. La liste elle-même se prouve dans les tests de `Repas` et de bout en bout.
 */
final class VoiceMealPlannerInMemory implements VoiceMealPlanner
{
    /** @var list<array{string, string, ?int}> les repas demandés `[userId, recipeId, servings]`, dans l'ordre */
    public array $planned = [];

    /** @var list<array{string, string, int}> les changements demandés `[userId, recipeId, servings]`, dans l'ordre */
    public array $changed = [];

    private ?PlannedSpokenMeal $result = null;
    private ?ChangedMealServings $change = null;
    private ?Throwable $failure = null;

    public function willReturn(PlannedSpokenMeal $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function willChange(ChangedMealServings $change): self
    {
        $this->change = $change;

        return $this;
    }

    public function willThrow(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function plan(string $userId, string $recipeId, ?int $servings = null): PlannedSpokenMeal
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->planned[] = [$userId, $recipeId, $servings];

        return $this->result ?? new PlannedSpokenMeal($recipeId, $servings ?? 4, alreadyPlanned: false, listCreated: false, previousListSent: false);
    }

    public function changeServings(string $userId, string $recipeId, int $servings): ChangedMealServings
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->changed[] = [$userId, $recipeId, $servings];

        return $this->change ?? ChangedMealServings::changed($recipeId, $servings);
    }
}
