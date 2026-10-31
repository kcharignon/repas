<?php

namespace Repas\Repas\Domain\Model;

/**
 * Une recette d'un utilisateur retenue ou proposée pour une phrase (`RecipePhraseResolver`, ADR 0029 décision 13) : son identifiant et son nom.
 * Toujours une recette de l'utilisateur qui parle.
 */
final readonly class RecipeChoice
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
