<?php

namespace Repas\Tests\Helper\InMemoryLoader;

use Repas\Repas\Application\Loader\SpokenPhraseMemory;

/**
 * Double idiot : renvoie le slug programmé pour une clé de phrase (rien pour les autres) et enregistre ses appels.
 */
class SpokenPhraseMemoryInMemoryLoader implements SpokenPhraseMemory
{
    /** @var list<array{string, string}> les appels `[userId, clé]` */
    public array $calls = [];

    /**
     * @param array<string, string> $remembered clé de phrase => slug
     */
    public function __construct(
        private readonly array $remembered = [],
    ) {
    }

    public function rememberedSlug(string $userId, string $phraseKey): ?string
    {
        $this->calls[] = [$userId, $phraseKey];

        return $this->remembered[$phraseKey] ?? null;
    }
}
