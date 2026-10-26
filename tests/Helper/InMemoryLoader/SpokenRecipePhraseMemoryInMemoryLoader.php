<?php

namespace Repas\Tests\Helper\InMemoryLoader;

use Repas\Repas\Application\Loader\SpokenRecipePhraseMemory;

/**
 * Double idiot : renvoie l'identifiant de recette programmé pour une clé de phrase (rien pour les autres) et enregistre ses appels.
 */
class SpokenRecipePhraseMemoryInMemoryLoader implements SpokenRecipePhraseMemory
{
    /** @var list<array{string, string}> les appels `[userId, clé]` */
    public array $calls = [];

    /**
     * @param array<string, string> $remembered clé de phrase => identifiant de recette
     */
    public function __construct(
        private readonly array $remembered = [],
    ) {
    }

    public function rememberedId(string $userId, string $phraseKey): ?string
    {
        $this->calls[] = [$userId, $phraseKey];

        return $this->remembered[$phraseKey] ?? null;
    }
}
