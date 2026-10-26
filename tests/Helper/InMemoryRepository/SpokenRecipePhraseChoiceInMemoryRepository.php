<?php

namespace Repas\Tests\Helper\InMemoryRepository;

use DateTimeImmutable;
use Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository;

/**
 * Double en mémoire du port : le même contrat que la table, une ligne par (utilisateur, phrase), le dernier choix l'emporte.
 */
class SpokenRecipePhraseChoiceInMemoryRepository implements SpokenRecipePhraseChoiceRepository
{
    /** @var array<string, array{userId: string, phrase: string, recipeId: string, times: int, updatedAt: DateTimeImmutable}> */
    private array $rows = [];

    public function remember(string $userId, string $phraseKey, string $recipeId, DateTimeImmutable $now): void
    {
        $id = $userId . '|' . $phraseKey;
        $times = isset($this->rows[$id]) && $this->rows[$id]['recipeId'] === $recipeId ? $this->rows[$id]['times'] + 1 : 1;
        $this->rows[$id] = ['userId' => $userId, 'phrase' => $phraseKey, 'recipeId' => $recipeId, 'times' => $times, 'updatedAt' => $now];
    }

    public function deleteByUser(string $userId): void
    {
        $this->rows = array_filter($this->rows, static fn(array $row): bool => $row['userId'] !== $userId);
    }

    public function deleteByRecipeId(string $userId, string $recipeId): void
    {
        $this->rows = array_filter($this->rows, static fn(array $row): bool => $row['userId'] !== $userId || $row['recipeId'] !== $recipeId);
    }

    /**
     * @return list<array{userId: string, phrase: string, recipeId: string, times: int, updatedAt: DateTimeImmutable}>
     */
    public function all(): array
    {
        return array_values($this->rows);
    }
}
