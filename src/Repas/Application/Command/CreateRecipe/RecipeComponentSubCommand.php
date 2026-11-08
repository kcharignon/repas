<?php

namespace Repas\Repas\Application\Command\CreateRecipe;


/**
 * Une recette utilisée par la recette qu'on écrit (ADR 0040) : son identifiant et « combien de fois telle qu'elle est écrite ». L'ordre de la liste est la
 * position. Partagée par `CreateRecipeCommand` et `UpdateRecipeCommand` : les deux passent par `RecipeComponentsWriter`.
 */
final readonly class RecipeComponentSubCommand
{
    public function __construct(
        public string $recipeId,
        public float $multiplier,
    ) {
    }
}
