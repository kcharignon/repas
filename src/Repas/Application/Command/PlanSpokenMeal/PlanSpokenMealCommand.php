<?php

namespace Repas\Repas\Application\Command\PlanSpokenMeal;

/**
 * Planifie un repas dit à voix haute dans la liste active de l'utilisateur, en créant la liste s'il n'en a pas (ADR 0029 décision 13, VOX-305). Le
 * handler renvoie un `Shared\Application\Dto\PlannedSpokenMeal` (exception à « une commande ne renvoie rien », ADR 0015 §12 : le résultat dit à
 * l'appelant si la recette y était déjà, si une liste a été créée et pour combien de personnes).
 */
final readonly class PlanSpokenMealCommand
{
    public const int MIN_SERVINGS = 1;
    public const int MAX_SERVINGS = 99;

    /**
     * @param string $newShoppingListId identifiant de la liste créée si l'utilisateur n'en a pas d'active
     * @param ?int   $servings          le nombre de personnes dit (1 à 99) ; `null` : celui du compte
     */
    public function __construct(
        public string $ownerId,
        public string $recipeId,
        public string $newShoppingListId,
        public ?int $servings = null,
    ) {
    }
}
