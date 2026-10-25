<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Dto\IngredientSuggestion;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Dto\SpokenResolution;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Throwable;

/**
 * Double idiot du port `SpokenIngredientResolver` : renvoie ce qu'on lui a programmé pour une phrase (rien pour les autres) et note ses
 * appels. Il ne classe rien : le choix de l'ingrédient se prouve dans les tests du classeur et de l'adaptateur de `Repas`.
 */
final class SpokenIngredientResolverInMemory implements SpokenIngredientResolver
{
    /** @var array<string, ResolvedIngredient> */
    private array $answers = [];

    /** @var array<string, list<IngredientSuggestion>> */
    private array $suggestions = [];

    private ?Throwable $rememberFailure = null;

    /** @var list<array{string, string}> les appels à `resolveOrSuggest` `[userId, phrase]`, dans l'ordre */
    public array $asked = [];

    /** @var list<array{string, string, string}> les appels à `remember` `[userId, phrase, slug]`, dans l'ordre */
    public array $remembered = [];

    public function willResolve(string $phrase, ResolvedIngredient $ingredient): self
    {
        $this->answers[$phrase] = $ingredient;

        return $this;
    }

    /**
     * @param list<IngredientSuggestion> $suggestions
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

    public function resolveOrSuggest(string $userId, string $phrase): SpokenResolution
    {
        $this->asked[] = [$userId, $phrase];

        return isset($this->answers[$phrase]) ? SpokenResolution::found($this->answers[$phrase]) : SpokenResolution::unknown($this->suggestions[$phrase] ?? []);
    }

    public function remember(string $userId, string $phrase, string $ingredientSlug): void
    {
        if ($this->rememberFailure !== null) {
            throw $this->rememberFailure;
        }
        $this->remembered[] = [$userId, $phrase, $ingredientSlug];
    }
}
