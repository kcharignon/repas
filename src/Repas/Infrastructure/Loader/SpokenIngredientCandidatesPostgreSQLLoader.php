<?php

namespace Repas\Repas\Infrastructure\Loader;

use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Infrastructure\Reader\SpokenIngredientPhraseReader;

/**
 * Les ingrédients de l'utilisateur et son habitude de chacun : la lecture de `SpokenIngredientPhraseReader`, le même code que le Repository
 * de l'écriture (un rapport ou une lecture dit ce que l'écriture verra).
 */
final readonly class SpokenIngredientCandidatesPostgreSQLLoader implements SpokenIngredientCandidates
{
    public function __construct(
        private SpokenIngredientPhraseReader $reader,
    ) {
    }

    public function forUser(string $userId): array
    {
        return $this->reader->candidates($userId);
    }
}
