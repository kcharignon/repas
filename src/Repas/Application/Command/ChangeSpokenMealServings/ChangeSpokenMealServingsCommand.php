<?php

namespace Repas\Repas\Application\Command\ChangeSpokenMealServings;

/**
 * Fixe le nouveau total de personnes d'un repas déjà dans la liste active de l'utilisateur, dit à voix haute (ADR 0029 décision 13, VOX-305). Le
 * handler renvoie un `Shared\Application\Dto\ChangedMealServings` (exception à « une commande ne renvoie rien », ADR 0015 §12).
 */
final readonly class ChangeSpokenMealServingsCommand
{
    public function __construct(
        public string $ownerId,
        public string $recipeId,
        /** Le nouveau total, de 1 à 99. */
        public int $servings,
    ) {
    }
}
