<?php

namespace Repas\Repas\Application\Command\RememberSpokenRecipePhrase;

/**
 * Retient qu'une phrase dite à Alexa désigne une recette de l'utilisateur (ADR 0029 décision 13, VOX-305) : la prochaine fois, la même phrase donne
 * tout de suite cette recette.
 */
final readonly class RememberSpokenRecipePhraseCommand
{
    public function __construct(
        public string $userId,
        /** La phrase entendue, telle quelle : le handler en tire la clé normalisée. */
        public string $phrase,
        public string $recipeId,
    ) {
    }
}
