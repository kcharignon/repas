<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipe;


use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use Repas\Shared\Domain\Tool\Tab;

/**
 * Les composants du corps d'une recette (`RecipePayload::$components`) en sous-commandes, pour `CreateRecipeCommand` et `UpdateRecipeCommand` : `null`
 * (absent ou `null` dans le corps) reste `null` = inchangé, jamais confondu avec `[]` = tout retirer (ADR 0040 décision 24). Le contrôleur ne fait que
 * transposer, les bornes et les règles sont jugées par le cas d'usage.
 */
final readonly class RecipeComponentCommands
{
    private function __construct()
    {
    }

    /**
     * @return Tab<RecipeComponentSubCommand>|null
     */
    public static function from(RecipePayload $payload): ?Tab
    {
        if ($payload->components === null) {
            return null;
        }

        return new Tab(
            array_map(
                // Le multiplicateur est déjà jugé un nombre JSON (entier ou décimal) par le corps de la requête
                static fn(RecipeComponentPayload $component): RecipeComponentSubCommand => new RecipeComponentSubCommand($component->recipeId, (float) $component->multiplier),
                array_values($payload->components),
            ),
            RecipeComponentSubCommand::class,
        );
    }
}
