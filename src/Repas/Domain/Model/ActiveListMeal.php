<?php

namespace Repas\Repas\Domain\Model;

/**
 * Un repas de la liste de courses active de l'utilisateur (ADR 0029 décision 12, VOX-306) : une recette planifiée et le nombre de personnes pour
 * lequel elle l'est. Une valeur lue, jamais un agrégat.
 */
final readonly class ActiveListMeal
{
    public function __construct(
        /** L'identifiant de la recette (un appelant ne le croit pas : les écritures vérifient qu'elle est à l'utilisateur). */
        public string $recipeId,
        public string $name,
        /** Le nombre de personnes du repas dans la liste (1 à 99). */
        public int $servings,
    ) {
    }
}
