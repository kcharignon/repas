<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Model\IngredientDistinctPair;

/**
 * Double en mémoire du port : le même contrat que la table, une ligne par (utilisateur, paire rangée), la première date est gardée
 * (`ON CONFLICT DO NOTHING`).
 */
class IngredientDistinctInMemoryRepository implements IngredientDistinctRepository
{
    /** @var array<string, IngredientDistinctPair> */
    private array $pairs = [];

    /**
     * @param list<IngredientDistinctPair> $pairs
     */
    public function __construct(array $pairs = [])
    {
        foreach ($pairs as $pair) {
            $this->pairs[$pair->getId()] = $pair;
        }
    }

    public function save(IngredientDistinctPair $pair): bool
    {
        if (isset($this->pairs[$pair->getId()])) {
            return false;
        }
        $this->pairs[$pair->getId()] = $pair;

        return true;
    }

    public function deleteByUser(string $userId): void
    {
        $this->pairs = array_filter($this->pairs, static fn(IngredientDistinctPair $pair): bool => $pair->getUserId() !== $userId);
    }

    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void
    {
        $this->pairs = array_filter(
            $this->pairs,
            static fn(IngredientDistinctPair $pair): bool => $pair->getUserId() !== $userId || !$pair->names($ingredientSlug),
        );
    }

    /**
     * @return list<IngredientDistinctPair>
     */
    public function all(): array
    {
        return array_values($this->pairs);
    }
}
