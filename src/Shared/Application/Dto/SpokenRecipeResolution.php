<?php

namespace Repas\Shared\Application\Dto;

/**
 * Ce que `SpokenRecipeResolver::resolveOrSuggest()` rend pour une phrase prononcée (ADR 0029 décision 13) : SOIT la recette retenue, SOIT — la
 * recette est inconnue, ou plusieurs se valent et on ne devine pas — au plus trois propositions (liste vide : rien d'assez proche). Jamais les deux.
 */
final readonly class SpokenRecipeResolution
{
    /**
     * @param list<RecipeSuggestion> $suggestions
     */
    private function __construct(
        public ?ResolvedRecipe $recipe,
        public array $suggestions,
    ) {
    }

    public static function found(ResolvedRecipe $recipe): self
    {
        return new self($recipe, []);
    }

    /**
     * @param list<RecipeSuggestion> $suggestions
     */
    public static function unknown(array $suggestions = []): self
    {
        return new self(null, $suggestions);
    }
}
