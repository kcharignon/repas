<?php

namespace Repas\Shared\Application\Dto;

/**
 * Un ingrédient de l'utilisateur que `SpokenIngredientResolver::resolveOrSuggest()` propose pour une phrase qu'il n'a pas pu choisir tout seul
 * (ADR 0029 décision 11, VOX-303) : un simple objet de transport entre le contexte `Repas` et le contexte `Voice`.
 */
final readonly class IngredientSuggestion
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $departmentName,
        /** Le slug du rayon, pour reconnaître le rayon dit à voix haute quand deux ingrédients de même nom se départagent. */
        public string $departmentSlug = '',
    ) {
    }
}
