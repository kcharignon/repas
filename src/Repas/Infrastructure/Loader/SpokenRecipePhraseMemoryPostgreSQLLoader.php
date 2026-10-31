<?php

namespace Repas\Repas\Infrastructure\Loader;

use Repas\Repas\Application\Loader\SpokenRecipePhraseMemory;
use Repas\Repas\Infrastructure\Reader\SpokenRecipePhraseReader;

final readonly class SpokenRecipePhraseMemoryPostgreSQLLoader implements SpokenRecipePhraseMemory
{
    public function __construct(
        private SpokenRecipePhraseReader $reader,
    ) {
    }

    public function rememberedId(string $userId, string $phraseKey): ?string
    {
        return $this->reader->rememberedId($userId, $phraseKey);
    }
}
