<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Dto\RecipeSuggestion;
use Repas\Shared\Application\Dto\ResolvedRecipe;
use Repas\Shared\Application\Dto\SpokenRecipeResolution;
use Repas\Shared\Application\Interface\SpokenRecipeResolver;
use Throwable;

/**
 * Double idiot du port `SpokenRecipeResolver` : renvoie ce qu'on lui a programmé pour une phrase (rien pour les autres) et note ses appels. Il ne classe
 * rien : le choix de la recette se prouve dans les tests de l'adaptateur de `Repas`.
 */
final class SpokenRecipeResolverInMemory implements SpokenRecipeResolver
{
    /** @var array<string, ResolvedRecipe> */
    private array $answers = [];

    /** @var array<string, list<RecipeSuggestion>> */
    private array $suggestions = [];

    private ?Throwable $rememberFailure = null;

    /** @var list<array{string, string}> les appels à `resolveOrSuggest` `[userId, phrase]`, dans l'ordre */
    public array $asked = [];

    /** @var list<array{string, string, string}> les appels à `remember` `[userId, phrase, recipeId]`, dans l'ordre */
    public array $remembered = [];

    public function willResolve(string $phrase, ResolvedRecipe $recipe): self
    {
        $this->answers[$phrase] = $recipe;

        return $this;
    }

    /**
     * @param list<RecipeSuggestion> $suggestions
     */
    public function willSuggest(string $phrase, array $suggestions): self
    {
        $this->suggestions[$phrase] = $suggestions;

        return $this;
    }

    public function willFailToRemember(Throwable $failure): self
    {
        $this->rememberFailure = $failure;

        return $this;
    }

    public function resolveOrSuggest(string $userId, string $phrase): SpokenRecipeResolution
    {
        $this->asked[] = [$userId, $phrase];

        return isset($this->answers[$phrase]) ? SpokenRecipeResolution::found($this->answers[$phrase]) : SpokenRecipeResolution::unknown($this->suggestions[$phrase] ?? []);
    }

    public function remember(string $userId, string $phrase, string $recipeId): void
    {
        if ($this->rememberFailure !== null) {
            throw $this->rememberFailure;
        }
        $this->remembered[] = [$userId, $phrase, $recipeId];
    }
}
