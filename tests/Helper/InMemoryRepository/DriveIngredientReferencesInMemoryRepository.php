<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Shared\Application\Dto\DriveChoiceFingerprint;
use Repas\Shared\Application\Interface\DriveIngredientReferences;

/**
 * Le port `Shared` qui repointe les choix du drive, sur des tableaux : `[identifiant du choix => [utilisateur, slug, nombre de produits]]`.
 * Le SQL est prouvé sur la vraie base (`tests/Drive/OutputAdapter/DriveIngredientReferencesRepositoryTest`).
 */
final class DriveIngredientReferencesInMemoryRepository implements DriveIngredientReferences
{
    /** @var array<string, array{user: string, slug: string, products: int}> */
    public array $choices = [];
    /** @var list<array{user: string, map: array<string, string>}> */
    public array $repointCalls = [];
    /** Quand vrai, `repoint()` ne fait rien : l'empreinte doit voir des choix restés sur les anciens slugs. */
    public bool $ignoreRepoint = false;

    public function fingerprint(string $userId): DriveChoiceFingerprint
    {
        $mine = array_filter($this->choices, static fn(array $choice): bool => $choice['user'] === $userId);
        ksort($mine);

        return new DriveChoiceFingerprint(
            count($mine),
            array_sum(array_column($mine, 'products')),
            '0',
            md5(implode('|', array_map(static fn(array $choice): string => (string) $choice['products'], $mine))),
            array_map(static fn(array $choice): string => $choice['slug'], $mine),
            array_map(static fn(array $choice): int => $choice['products'], $mine),
        );
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
