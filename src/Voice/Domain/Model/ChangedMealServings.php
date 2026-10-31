<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que `MealPlanCommandPort::changeServings()` a fait (ADR 0029 décision 13, VOX-305) : le repas a un nouveau nombre de personnes, ou il n'est plus
 * dans la liste active (rien n'est écrit).
 */
final readonly class ChangedMealServings
{
    private function __construct(
        public bool $found,
        public string $recipeName,
        public int $servings,
    ) {
    }

    public static function changed(string $recipeName, int $servings): self
    {
        return new self(true, $recipeName, $servings);
    }

    /** Pas de liste active, ou la recette n'y est plus : rien n'a été écrit. */
    public static function notPlanned(string $recipeName = ''): self
    {
        return new self(false, $recipeName, 0);
    }
}
