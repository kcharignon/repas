<?php

namespace Repas\Shared\Application\Dto;

/**
 * La recette que `SpokenRecipeResolver` retient pour une phrase prononcée (ADR 0029 décision 13, VOX-305) : un simple objet de transport entre
 * le contexte `Repas` et le contexte `Voice`. Toujours une recette de l'utilisateur qui parle.
 */
final readonly class ResolvedRecipe
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
