<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que `MealPlanCommandPort::changeServings()` a fait (ADR 0029 décision 13, VOX-305) : le repas a un nouveau nombre de personnes, ou il n'est plus
 * dans une liste en cours (rien n'est écrit).
 */
final readonly class ChangedMealServings
{
    private function __construct(
        public bool $found,
        public string $recipeName,
        public int $servings,
        /** Une liste de la planification du repas est partie au drive : elle n'est jamais modifiée, donc rien ne l'est (ADR 0036 décision 9). */
        public bool $listSent = false,
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

    /** Le repas est dans une planification dont une liste est partie au drive : rien n'a été écrit. */
    public static function listSent(string $recipeName): self
    {
        return new self(false, $recipeName, 0, listSent: true);
    }
}
