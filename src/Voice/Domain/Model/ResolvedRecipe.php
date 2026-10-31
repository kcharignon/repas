<?php

namespace Repas\Voice\Domain\Model;

/**
 * La recette que `RecipeResolutionCommandPort` retient pour une phrase prononcée (ADR 0029 décision 13, VOX-305), telle que ce contexte la reçoit de `Repas` (ADR 0035).
 * Toujours une recette de l'utilisateur qui parle.
 */
final readonly class ResolvedRecipe
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
