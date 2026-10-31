<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que `MealPlanCommandPort::plan()` a fait (ADR 0029 décision 13, VOX-305) : le repas a été planifié, ou il l'était déjà (rien n'est écrit, et
 * `servings` est alors le nombre de personnes pour lequel il est prévu).
 */
final readonly class PlannedSpokenMeal
{
    public function __construct(
        public string $recipeName,
        /** Le nombre de personnes du repas dans la liste : celui qui vient d'être planifié, ou celui qui y était déjà. */
        public int $servings,
        /** La recette était déjà dans la liste : rien n'a été écrit. */
        public bool $alreadyPlanned,
        /** Aucune liste active : une liste neuve a été commencée pour ce repas. */
        public bool $listCreated,
        /** Une liste envoyée au drive existait avant la liste neuve : Alexa le dit. */
        public bool $previousListSent,
    ) {
    }
}
