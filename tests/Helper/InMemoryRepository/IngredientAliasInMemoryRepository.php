<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Model\IngredientAlias;

/**
 * Double en mémoire du port : il tient le même contrat que la table, une ligne par (utilisateur, slug d'alias) et le premier alias
 * écrit n'est jamais remplacé (`ON CONFLICT DO NOTHING`).
 */
class IngredientAliasInMemoryRepository implements IngredientAliasRepository
{
    /** @var array<string, IngredientAlias> */
    private array $aliases = [];

    /** @param list<IngredientAlias> $aliases */
    public function __construct(array $aliases = [])
    {
        foreach ($aliases as $alias) {
            $this->aliases[$alias->getId()] = $alias;
        }
    }

    public function find(string $userId, string $aliasSlug): ?IngredientAlias
    {
        return $this->aliases[$userId . '|' . $aliasSlug] ?? null;
    }

    public function resolve(string $userId, array $slugs): array
    {
        $resolved = [];
        foreach ($slugs as $slug) {
            $alias = $this->find($userId, $slug);
            if ($alias !== null) {
                $resolved[$slug] = $alias->getIngredientSlug();
            }
        }

        return $resolved;
    }

    public function isAdopter(string $userId): bool
    {
        return array_any($this->aliases, static fn(IngredientAlias $alias): bool => $alias->getUserId() === $userId && $alias->isAdoption());
    }

    public function save(IngredientAlias $alias): bool
    {
        if (isset($this->aliases[$alias->getId()])) {
            return false;
        }
        $this->aliases[$alias->getId()] = $alias;

        return true;
    }

    public function deleteByUser(string $userId): void
    {
        $this->aliases = array_filter($this->aliases, static fn(IngredientAlias $alias): bool => $alias->getUserId() !== $userId);
    }

    public function deleteByAliasSlug(string $userId, string $aliasSlug): void
    {
        unset($this->aliases[$userId . '|' . $aliasSlug]);
    }

    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void
    {
        $this->aliases = array_filter(
            $this->aliases,
            static fn(IngredientAlias $alias): bool => $alias->getUserId() !== $userId || $alias->getIngredientSlug() !== $ingredientSlug,
        );
    }

    /**
     * @return list<IngredientAlias>
     */
    public function all(): array
    {
        return array_values($this->aliases);
    }
}
