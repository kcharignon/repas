<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\RecipePhraseCommandOHS;
use Repas\Repas\Domain\Model\RecipeChoice;
use Repas\Voice\Application\Port\RecipeResolutionCommandPort;
use Repas\Voice\Domain\Model\RecipeSuggestion;
use Repas\Voice\Domain\Model\ResolvedRecipe;
use Repas\Voice\Domain\Model\SpokenRecipeResolution;

/**
 * Couche anticorruption (ADR 0035) : ce que le classement de `Repas` rend pour une phrase parmi les recettes de l'utilisateur (la recette, des
 * propositions, ou le fait qu'il n'en a aucune) devient ce que le dialogue vocal attend. Rien n'est décidé ici.
 */
final readonly class RecipeResolutionCommandAdapter implements RecipeResolutionCommandPort
{
    public function __construct(
        private RecipePhraseCommandOHS $recipes,
    ) {
    }

    public function resolveOrSuggest(string $userId, string $phrase): SpokenRecipeResolution
    {
        $resolution = $this->recipes->resolveOrSuggest($userId, $phrase);

        if ($resolution->recipe !== null) {
            return SpokenRecipeResolution::found(new ResolvedRecipe($resolution->recipe->id, $resolution->recipe->name));
        }

        return SpokenRecipeResolution::unknown(
            array_map(static fn(RecipeChoice $choice): RecipeSuggestion => new RecipeSuggestion($choice->id, $choice->name), $resolution->suggestions),
            $resolution->hasRecipes,
        );
    }

    public function remember(string $userId, string $phrase, string $recipeId): void
    {
        $this->recipes->remember($userId, $phrase, $recipeId);
    }
}
