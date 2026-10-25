<?php

namespace Repas\Repas\Application\Command\RememberSpokenPhrase;

/**
 * Retient qu'une phrase dite à Alexa désigne un ingrédient de l'utilisateur (ADR 0029 décision 11, VOX-303) : la prochaine fois, la même
 * phrase donne tout de suite cet ingrédient.
 */
final readonly class RememberSpokenPhraseCommand
{
    public function __construct(
        public string $userId,
        /** La phrase entendue, telle quelle : le handler en tire la clé normalisée. */
        public string $phrase,
        public string $ingredientSlug,
    ) {
    }
}
