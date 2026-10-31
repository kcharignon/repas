<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce que le classement d'une phrase rend parmi les recettes d'un utilisateur (`RecipePhraseResolver`, ADR 0029 décision 13) : SOIT la recette
 * retenue, SOIT — la recette est inconnue, ou plusieurs se valent et on ne devine pas — au plus trois propositions : les recettes les PLUS
 * PROCHES de l'utilisateur, même lointaines ; la liste n'est vide que s'il n'a AUCUNE recette (`hasRecipes` faux). Jamais les deux.
 */
final readonly class RecipePhraseResolution
{
    /**
     * @param list<RecipeChoice> $suggestions
     */
    private function __construct(
        public ?RecipeChoice $recipe,
        public array $suggestions,
        /** L'utilisateur a au moins une recette. */
        public bool $hasRecipes = true,
    ) {
    }

    public static function found(RecipeChoice $recipe): self
    {
        return new self($recipe, []);
    }

    /**
     * @param list<RecipeChoice> $suggestions
     */
    public static function unknown(array $suggestions = [], bool $hasRecipes = true): self
    {
        return new self(null, $suggestions, $hasRecipes);
    }
}
