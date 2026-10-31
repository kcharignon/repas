<?php

namespace Repas\Repas\Application\Command\PlannedMeal;


/**
 * Les listes qui ont reçu quelque chose quand une recette est planifiée (ADR 0036 décision 11) : la liste principale d'abord, puis les listes des
 * types dans l'ordre où la recette les atteint. Un compte sans type n'a que la liste principale.
 */
final readonly class PlannedMealResult
{
    /**
     * @param list<PlannedMealList> $lists
     */
    public function __construct(
        public array $lists,
    ) {
    }
}
