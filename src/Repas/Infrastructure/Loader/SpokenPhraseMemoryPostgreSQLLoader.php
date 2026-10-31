<?php

namespace Repas\Repas\Infrastructure\Loader;

use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Infrastructure\Reader\SpokenIngredientPhraseReader;

final readonly class SpokenPhraseMemoryPostgreSQLLoader implements SpokenPhraseMemory
{
    public function __construct(
        private SpokenIngredientPhraseReader $reader,
    ) {
    }

    public function rememberedSlug(string $userId, string $phraseKey): ?string
    {
        return $this->reader->rememberedSlug($userId, $phraseKey);
    }
}
