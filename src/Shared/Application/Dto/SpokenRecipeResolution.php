<?php

namespace Repas\Shared\Application\Dto;

/**
 * Ce que `SpokenRecipeResolver::resolveOrSuggest()` rend pour une phrase prononcée (ADR 0029 décision 13) : SOIT la recette retenue, SOIT — la
 * recette est inconnue, ou plusieurs se valent et on ne devine pas — au plus trois propositions : les recettes les PLUS PROCHES de l'utilisateur, même
 * lointaines (2026-10-26) ; la liste n'est vide que s'il n'a AUCUNE recette (`hasRecipes` faux). Jamais les deux.
 */
final readonly class SpokenRecipeResolution
{
    /**
     * @param list<RecipeSuggestion> $suggestions
     */
    private function __construct(
        public ?ResolvedRecipe $recipe,
        public array $suggestions,
        /** L'utilisateur a au moins une recette : sinon Alexa le dit, au lieu d'un « je ne trouve pas » qui ne l'aide pas. */
        public bool $hasRecipes = true,
    ) {
    }

    public static function found(ResolvedRecipe $recipe): self
    {
        return new self($recipe, []);
    }

    /**
     * @param list<RecipeSuggestion> $suggestions
     */
    public static function unknown(array $suggestions = [], bool $hasRecipes = true): self
    {
        return new self(null, $suggestions, $hasRecipes);
    }
}
