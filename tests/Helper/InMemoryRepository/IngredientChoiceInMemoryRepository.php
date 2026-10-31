<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Drive\Domain\Interface\IngredientChoiceRepository;
use Repas\Drive\Domain\Model\IngredientChoiceFingerprint;
use Repas\Drive\Domain\Model\IngredientChoiceMergeImpact;

/**
 * Les choix de produits du drive vus par ingrédient, sur des tableaux : `[identifiant du choix => [utilisateur, slug, nombre de produits]]`.
 * Le SQL est prouvé sur la vraie base (`tests/Drive/OutputAdapter/IngredientChoiceRepositoryTest` et `IngredientChoiceLoaderTest`).
 */
final class IngredientChoiceInMemoryRepository implements IngredientChoiceRepository
{
    /** @var array<string, array{user: string, slug: string, products: int, store?: string}> `store` : le magasin (enseigne comprise), `store` par défaut */
    public array $choices = [];
    /** @var list<array{user: string, map: array<string, string>}> */
    public array $repointCalls = [];
    /** Quand vrai, `repoint()` ne fait rien : l'empreinte doit voir des choix restés sur les anciens slugs. */
    public bool $ignoreRepoint = false;
    /** @var list<array{user: string, absorbed: string, kept: string}> */
    public array $mergeCalls = [];

    public function fingerprint(string $userId): IngredientChoiceFingerprint
    {
        $mine = array_filter($this->choices, static fn(array $choice): bool => $choice['user'] === $userId);
        ksort($mine);

        return new IngredientChoiceFingerprint(
            count($mine),
            array_sum(array_column($mine, 'products')),
            '0',
            md5(implode('|', array_map(static fn(array $choice): string => (string) $choice['products'], $mine))),
            array_map(static fn(array $choice): string => $choice['slug'], $mine),
            array_map(static fn(array $choice): int => $choice['products'], $mine),
        );
    }

    public function referencedSlugs(string $userId): array
    {
        $slugs = array_values(array_unique(array_column(array_filter($this->choices, static fn(array $choice): bool => $choice['user'] === $userId), 'slug')));
        sort($slugs);

        return $slugs;
    }

    /**
     * @return array<string, list<string>>
     */
    public function referencedSlugsOfEveryUser(): array
    {
        $slugs = [];
        foreach (array_unique(array_column($this->choices, 'user')) as $userId) {
            $slugs[$userId] = $this->referencedSlugs($userId);
        }
        ksort($slugs);

        return $slugs;
    }

    /**
     * Ce que `merge()` ferait, sans rien écrire.
     */
    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): IngredientChoiceMergeImpact
    {
        $moved = $dropped = $products = 0;
        foreach ($this->choices as $choice) {
            if ($choice['user'] !== $userId || $choice['slug'] !== $absorbedSlug) {
                continue;
            }
            if ($this->keptHasAChoiceIn($userId, $choice['store'] ?? 'store', $keptSlug)) {
                ++$dropped;
                $products += $choice['products'];
            } else {
                ++$moved;
            }
        }

        return new IngredientChoiceMergeImpact($moved, $dropped, $products);
    }

    public function merge(string $userId, string $absorbedSlug, string $keptSlug): IngredientChoiceMergeImpact
    {
        $this->mergeCalls[] = ['user' => $userId, 'absorbed' => $absorbedSlug, 'kept' => $keptSlug];
        $impact = $this->mergeImpact($userId, $absorbedSlug, $keptSlug);
        foreach ($this->choices as $id => $choice) {
            if ($choice['user'] !== $userId || $choice['slug'] !== $absorbedSlug) {
                continue;
            }
            if ($this->keptHasAChoiceIn($userId, $choice['store'] ?? 'store', $keptSlug)) {
                unset($this->choices[$id]);
            } else {
                $this->choices[$id]['slug'] = $keptSlug;
            }
        }

        return $impact;
    }

    private function keptHasAChoiceIn(string $userId, string $store, string $keptSlug): bool
    {
        return array_any($this->choices, static fn(array $choice): bool => $choice['user'] === $userId && $choice['slug'] === $keptSlug && ($choice['store'] ?? 'store') === $store);
    }

    public function repoint(string $userId, array $slugMap): int
    {
        $this->repointCalls[] = ['user' => $userId, 'map' => $slugMap];
        if ($this->ignoreRepoint) {
            return 0;
        }

        $repointed = 0;
        foreach ($this->choices as $id => $choice) {
            if ($choice['user'] === $userId && isset($slugMap[$choice['slug']])) {
                $this->choices[$id]['slug'] = $slugMap[$choice['slug']];
                ++$repointed;
            }
        }

        return $repointed;
    }
}
