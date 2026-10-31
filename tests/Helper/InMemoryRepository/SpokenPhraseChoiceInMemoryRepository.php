<?php

namespace Repas\Tests\Helper\InMemoryRepository;

use DateTimeImmutable;
use Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository;

/**
 * Double en mémoire du port : le même contrat que la table, une ligne par (utilisateur, phrase), le dernier choix l'emporte.
 */
class SpokenPhraseChoiceInMemoryRepository implements SpokenPhraseChoiceRepository
{
    /** @var array<string, array{userId: string, phrase: string, slug: string, times: int, updatedAt: DateTimeImmutable}> */
    private array $rows = [];

    /** @var list<array{string, string}> les lectures `[userId, clé]`, dans l'ordre */
    public array $reads = [];

    public function rememberedSlug(string $userId, string $phraseKey): ?string
    {
        $this->reads[] = [$userId, $phraseKey];

        return $this->rows[$userId . '|' . $phraseKey]['slug'] ?? null;
    }

    public function remember(string $userId, string $phraseKey, string $ingredientSlug, DateTimeImmutable $now): void
    {
        $id = $userId . '|' . $phraseKey;
        $times = isset($this->rows[$id]) && $this->rows[$id]['slug'] === $ingredientSlug ? $this->rows[$id]['times'] + 1 : 1;
        $this->rows[$id] = ['userId' => $userId, 'phrase' => $phraseKey, 'slug' => $ingredientSlug, 'times' => $times, 'updatedAt' => $now];
    }

    public function deleteByUser(string $userId): void
    {
        $this->rows = array_filter($this->rows, static fn(array $row): bool => $row['userId'] !== $userId);
    }

    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void
    {
        $this->rows = array_filter($this->rows, static fn(array $row): bool => $row['userId'] !== $userId || $row['slug'] !== $ingredientSlug);
    }

    public function repoint(string $userId, string $fromSlug, string $toSlug): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['userId'] === $userId && $row['slug'] === $fromSlug) {
                $this->rows[$id]['slug'] = $toSlug;
            }
        }
    }

    /**
     * @return list<array{userId: string, phrase: string, slug: string, times: int, updatedAt: DateTimeImmutable}>
     */
    public function all(): array
    {
        return array_values($this->rows);
    }
}
