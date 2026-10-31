<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;

/**
 * Double en mémoire du port : il tient le même contrat que la table, une ligne par (utilisateur, slug d'alias) et le premier alias
 * écrit n'est jamais remplacé (`ON CONFLICT DO NOTHING`). Par défaut il ne modélise pas l'alias orphelin (dont l'ingrédient
 * n'existe plus) : c'est le SQL du vrai dépôt qui l'ignore, prouvé sur base réelle (`IngredientAliasRepositoryTest`) ; un test qui en a besoin
 * donne `$targetExists`.
 */
class IngredientAliasInMemoryRepository implements IngredientAliasRepository
{
    /** @var array<string, IngredientAlias> */
    private array $aliases = [];

    /** @var list<array{string, list<string>}> utilisateur et slugs reçus à chaque appel de `resolve()` ou `resolveOwned()`, dans l'ordre des appels */
    public array $resolveCalls = [];

    /**
     * @param list<IngredientAlias> $aliases
     * @param (\Closure(string, string): bool)|null $targetExists dit si l'ingrédient désigné par un alias existe et appartient à l'utilisateur (utilisateur,
     *        slug de l'ingrédient) : programmé, le double ignore les alias orphelins comme le fait le SQL du vrai dépôt
     * @param (\Closure(string, string): bool)|null $owns dit si un slug est un ingrédient de l'utilisateur (utilisateur, slug) pour `resolveOwned()` ;
     *        sans programmation, tout slug lui appartient
     */
    public function __construct(array $aliases = [], private readonly ?\Closure $targetExists = null, private readonly ?\Closure $owns = null)
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
        $this->resolveCalls[] = [$userId, $slugs];

        return $this->validAliases($userId, $slugs);
    }

    public function resolveOwned(string $userId, array $slugs): array
    {
        $this->resolveCalls[] = [$userId, $slugs];

        // Comme le SQL : l'alias valide l'emporte sur le slug reçu, qui répond sinon s'il est lui-même un ingrédient de l'utilisateur
        $aliases = $this->validAliases($userId, $slugs);
        $owned = [];
        foreach ($slugs as $slug) {
            if (isset($aliases[$slug])) {
                $owned[$slug] = $aliases[$slug];
            } elseif ($this->owns === null || ($this->owns)($userId, $slug)) {
                $owned[$slug] = $slug;
            }
        }

        return $owned;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, string>
     */
    private function validAliases(string $userId, array $slugs): array
    {
        $resolved = [];
        foreach ($slugs as $slug) {
            $alias = $this->find($userId, $slug);
            if ($alias !== null && ($this->targetExists === null || ($this->targetExists)($userId, $alias->getIngredientSlug()))) {
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

    public function mergeInto(string $userId, string $absorbedSlug, string $keptSlug, \DateTimeImmutable $now): void
    {
        // Mêmes règles que le SQL : l'alias dont la clé est le gardé disparaît, ceux qui désignaient l'absorbé désignent le gardé (source
        // `merge`), puis l'alias de la fusion remplace tout alias qui avait déjà la clé de l'absorbé
        unset($this->aliases[$userId . '|' . $keptSlug]);
        foreach ($this->aliases as $id => $alias) {
            if ($alias->getUserId() === $userId && $alias->getIngredientSlug() === $absorbedSlug) {
                $this->aliases[$id] = IngredientAlias::load([
                    'user_id' => $userId,
                    'alias_slug' => $alias->getAliasSlug(),
                    'ingredient_slug' => $keptSlug,
                    'kind' => $alias->getKind(),
                    'source' => 'merge',
                    'created_at' => $alias->getCreatedAt(),
                ]);
            }
        }
        $this->aliases[$userId . '|' . $absorbedSlug] = IngredientAlias::create($userId, $absorbedSlug, $keptSlug, AliasKind::MERGE, 'merge', $now);
    }

    /**
     * @return list<IngredientAlias>
     */
    public function all(): array
    {
        return array_values($this->aliases);
    }
}
