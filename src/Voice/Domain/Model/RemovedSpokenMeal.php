<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que `MealPlanCommandPort::removeMeal()` a fait (ADR 0029 décision 12, VOX-306) : le repas a quitté la liste, ou son nombre de personnes a baissé, ou il
 * n'était pas dans la liste active (rien n'est écrit).
 */
final readonly class RemovedSpokenMeal
{
    private function __construct(
        public bool $found,
        public string $recipeName,
        /** Le repas a quitté la liste (tout retiré, ou autant de personnes que le repas en comptait). */
        public bool $removedAll,
        /** Le nombre de personnes qui reste quand le repas n'a pas quitté la liste (0 sinon). */
        public int $servings,
    ) {
    }

    public static function removed(string $recipeName): self
    {
        return new self(true, $recipeName, true, 0);
    }

    public static function lowered(string $recipeName, int $servings): self
    {
        return new self(true, $recipeName, false, $servings);
    }

    /** Pas de liste active, ou la recette n'y est plus : rien n'a été écrit. */
    public static function notPlanned(string $recipeName = ''): self
    {
        return new self(false, $recipeName, false, 0);
    }
}
