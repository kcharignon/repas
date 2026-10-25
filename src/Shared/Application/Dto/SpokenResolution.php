<?php

namespace Repas\Shared\Application\Dto;

/**
 * Ce que `SpokenIngredientResolver::resolveOrSuggest()` rend pour une phrase prononcée (ADR 0029 décisions 3 et 11) : SOIT l'ingrédient retenu,
 * SOIT — le produit est inconnu — au plus trois propositions (liste vide : rien d'assez proche). Jamais les deux.
 */
final readonly class SpokenResolution
{
    /**
     * @param list<IngredientSuggestion> $suggestions
     */
    private function __construct(
        public ?ResolvedIngredient $ingredient,
        public array $suggestions,
    ) {
    }

    public static function found(ResolvedIngredient $ingredient): self
    {
        return new self($ingredient, []);
    }

    /**
     * @param list<IngredientSuggestion> $suggestions
     */
    public static function unknown(array $suggestions = []): self
    {
        return new self(null, $suggestions);
    }
}
