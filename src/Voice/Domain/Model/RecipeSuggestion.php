<?php

namespace Repas\Voice\Domain\Model;

/**
 * Une recette de l'utilisateur que `RecipeResolutionCommandPort::resolveOrSuggest()` propose pour une phrase qu'il n'a pas pu choisir tout seul
 * (ADR 0029 décision 13, VOX-305).
 */
final readonly class RecipeSuggestion
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
