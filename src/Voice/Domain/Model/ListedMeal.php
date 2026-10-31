<?php

namespace Repas\Voice\Domain\Model;

/**
 * Un repas de la liste de courses active de l'utilisateur tel que la commande vocale le lit (ADR 0029 décision 12, VOX-306) : une recette planifiée
 * et le nombre de personnes pour lequel elle l'est. Vu par la commande vocale.
 */
final readonly class ListedMeal
{
    public function __construct(
        /** L'identifiant de la recette (jamais cru par `Voice` : les ports le vérifient). */
        public string $recipeId,
        public string $name,
        /** Le nombre de personnes du repas dans la liste (1 à 99). */
        public int $servings,
    ) {
    }
}
