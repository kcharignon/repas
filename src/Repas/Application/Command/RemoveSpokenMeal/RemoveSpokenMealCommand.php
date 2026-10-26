<?php

namespace Repas\Repas\Application\Command\RemoveSpokenMeal;

/**
 * Retire un repas de la liste active de l'utilisateur, ou en baisse le nombre de personnes, dit à voix haute (ADR 0029 décision 12, VOX-306). Le handler
 * renvoie un `Shared\Application\Dto\RemovedSpokenMeal` (exception à « une commande ne renvoie rien », ADR 0015 §12).
 */
final readonly class RemoveSpokenMealCommand
{
    public const int MIN_SERVINGS = 1;
    public const int MAX_SERVINGS = 99;

    public function __construct(
        public string $ownerId,
        public string $recipeId,
        /** Le nombre de personnes à enlever (1 à 99) ; `null` : le repas entier. */
        public ?int $servings = null,
    ) {
    }
}
