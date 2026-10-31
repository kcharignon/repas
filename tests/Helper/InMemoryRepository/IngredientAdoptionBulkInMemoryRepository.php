<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Model\AdoptionRepointed;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\AggregateSnapshot;
use Repas\Repas\Domain\Model\IngredientToSeed;

/**
 * Le port des écritures en masse de la migration d'adoption (ING-003) sur de simples tableaux, pour tester l'orchestration du handler
 * sans base : mêmes règles que `IngredientAdoptionBulkPostgreSQLRepository` (communs sans adoption valide, alias orphelin remplacé,
 * copie conforme réutilisée, repointage par propriétaire avec version +1, empreinte vue par le NOM). Le SQL, lui, est prouvé sur la vraie
 * base (`tests/Repas/OutputAdapter/IngredientAdoptionBulkRepositoryTest`).
 *
 * L'état est public : un test le prépare et le relit directement. Une transaction n'existe pas ici : une erreur du handler ne défait rien,
 * c'est le bus de commandes réel qui l'annule (prouvé sur la base).
 */
final class IngredientAdoptionBulkInMemoryRepository implements IngredientAdoptionBulkRepository
{
    /** @var array<string, array{name: string, department: string, creator: ?string, adoptedFrom: ?string, isBase?: bool}> par slug (`isBase` : le marqueur « de base », faux quand il est absent) */
    public array $ingredients = [];
    /** @var list<array{id: string, ingredient: ?string}> */
    public array $conversions = [];
    /** @var array<string, array<string, array{ingredient: string, kind: string, source: string}>> par utilisateur puis slug d'alias */
    public array $aliases = [];
    /** @var list<string> */
    public array $administrators = [];
    /** @var array<string, array{author: string, version: int, rows: list<array{ingredient: string, quantity: float, unit: string}>}> */
    public array $recipes = [];
    /** @var array<string, array{owner: string, version: int, status: string, sentAt: ?string, rows: list<array{ingredient: string, quantity: float, unit: string}>, ingredients: list<array{ingredient: string, quantity: float, unit: string}>}> */
    public array $lists = [];
    /** @var list<string> les appels, dans l'ordre */
    public array $calls = [];
    /** Quand vrai, le repointage corrompt la quantité de la première ligne de recette repointée : l'empreinte doit le voir. */
    public bool $corruptQuantities = false;

    public function lock(string $userId): void
    {
        $this->calls[] = 'lock';
    }

    public function takenSlugs(string $userId): array
    {
        return array_values(array_filter(array_keys($this->ingredients), static fn(string $slug): bool => str_ends_with($slug, $userId)));
    }

    public function writeAliases(string $userId, array $copySlugByCommonSlug, string $source, DateTimeImmutable $now): array
    {
        $this->calls[] = 'writeAliases';
        $written = [];
        foreach ($copySlugByCommonSlug as $common => $copy) {
            if ($this->validAdoption($userId, $common) !== null) {
                continue;
            }
            $this->aliases[$userId][$common] = ['ingredient' => $copy, 'kind' => 'adoption', 'source' => $source];
            $written[$common] = $copy;
        }

        return $written;
    }

    public function ownConversionsOf(array $commonSlugs): array
    {
        $own = array_values(array_filter($this->conversions, static fn(array $c): bool => in_array($c['ingredient'], $commonSlugs, true)));

        return array_map(static fn(array $c): array => ['id' => $c['id'], 'commonSlug' => $c['ingredient']], $own);
    }

    public function copyConversions(array $copies): int
    {
        $this->calls[] = 'copyConversions';
        foreach ($copies as $copy) {
            $this->conversions[] = ['id' => $copy['newId'], 'ingredient' => $copy['copySlug']];
        }

        return count($copies);
    }

    public function adoptions(string $userId): array
    {
        $adoptions = [];
        foreach (array_keys($this->aliases[$userId] ?? []) as $common) {
            if (($copy = $this->validAdoption($userId, $common)) !== null) {
                $adoptions[$common] = $copy;
            }
        }
        ksort($adoptions);

        return $adoptions;
    }

    public function repointToCopies(string $userId): AdoptionRepointed
    {
        $this->calls[] = 'repointToCopies';

        return $this->repoint($userId, $this->adoptions($userId));
    }

    public function snapshot(string $userId, array $watchedSlugs): AdoptionSnapshot
    {
        $isWatched = fn(string $slug): bool => in_array($slug, $watchedSlugs, true);
        $line = fn(array $row): string => ($this->ingredients[$row['ingredient']]['name'] ?? '?') . '|' . ($this->ingredients[$row['ingredient']]['department'] ?? '?') . '|' . $row['quantity'] . '|' . $row['unit'];

        $counts = $sums = $watchedCounts = ['recipe_row' => 0, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0];
        $sums = array_map(static fn(): string => '0', $sums);
        $recipes = [];
        foreach ($this->recipes as $id => $recipe) {
            if ($recipe['author'] !== $userId) {
                continue;
            }
            $tuples = array_map($line, $recipe['rows']);
            sort($tuples);
            $recipes[$id] = new AggregateSnapshot($recipe['version'], $tuples === [] ? '' : md5(implode("\n", $tuples)), '', array_filter($recipe['rows'], static fn(array $r): bool => $isWatched($r['ingredient'])) !== []);
            $counts['recipe_row'] += count($recipe['rows']);
            $sums['recipe_row'] = (string) ((float) $sums['recipe_row'] + array_sum(array_column($recipe['rows'], 'quantity')));
            $watchedCounts['recipe_row'] += count(array_filter($recipe['rows'], static fn(array $r): bool => $isWatched($r['ingredient'])));
        }
        $lists = [];
        foreach ($this->lists as $id => $list) {
            if ($list['owner'] !== $userId) {
                continue;
            }
            $tuples = [...array_map(static fn(array $r): string => 'R|' . $line($r), $list['rows']), ...array_map(static fn(array $r): string => 'I|' . $line($r), $list['ingredients'])];
            sort($tuples);
            $watchedHere = array_filter([...$list['rows'], ...$list['ingredients']], static fn(array $r): bool => $isWatched($r['ingredient'])) !== [];
            $lists[$id] = new AggregateSnapshot($list['version'], $tuples === [] ? '' : md5(implode("\n", $tuples)), $list['status'] . '|' . ($list['sentAt'] ?? ''), $watchedHere, $list['status'] === 'SENT');
            $counts['shopping_list_row'] += count($list['rows']);
            $counts['shopping_list_ingredient'] += count($list['ingredients']);
            $watchedCounts['shopping_list_row'] += count(array_filter($list['rows'], static fn(array $r): bool => $isWatched($r['ingredient'])));
            $watchedCounts['shopping_list_ingredient'] += count(array_filter($list['ingredients'], static fn(array $r): bool => $isWatched($r['ingredient'])));
            $sums['shopping_list_row'] = (string) ((float) $sums['shopping_list_row'] + array_sum(array_column($list['rows'], 'quantity')));
            $sums['shopping_list_ingredient'] = (string) ((float) $sums['shopping_list_ingredient'] + array_sum(array_column($list['ingredients'], 'quantity')));
        }

        return new AdoptionSnapshot($counts, $sums, $watchedCounts, $recipes, $lists);
    }

    public function nonConformingCopies(string $userId, array $adoptions): array
    {
        $bad = [];
        foreach ($adoptions as $common => $copy) {
            $c = $this->ingredients[$common] ?? null;
            $o = $this->ingredients[$copy] ?? null;
            if ($c === null || $o === null || $o['creator'] !== $userId || $o['adoptedFrom'] !== $common || $o['name'] !== $c['name'] || $o['department'] !== $c['department']) {
                $bad[] = $common;
            }
        }

        return $bad;
    }

    // ---- L'amorçage d'un compte (ING-008) : la source (catalogue du propriétaire, « de base ») et le périmètre

    public function lockSeeding(string $userId): void
    {
        $this->calls[] = 'lockSeeding';
    }

    public function isSeeded(string $userId): bool
    {
        return array_filter($this->aliases[$userId] ?? [], static fn(array $alias): bool => $alias['kind'] === 'adoption') !== [];
    }

    public function ingredientsToSeed(string $userId, string $ownerId, array $driveReferencedSlugs): array
    {
        $referenced = array_fill_keys($driveReferencedSlugs, true);
        foreach ($this->recipes as $recipe) {
            if ($recipe['author'] === $userId) {
                foreach ($recipe['rows'] as $row) {
                    $referenced[$row['ingredient']] = true;
                }
            }
        }
        foreach ($this->lists as $list) {
            if ($list['owner'] === $userId) {
                foreach ([...$list['rows'], ...$list['ingredients']] as $row) {
                    $referenced[$row['ingredient']] = true;
                }
            }
        }

        $catalog = array_filter($this->ingredients, static fn(array $i): bool => $i['creator'] === null || $i['creator'] === $ownerId);
        ksort($catalog);
        $result = [];
        foreach ($catalog as $slug => $ingredient) {
            $isBase = $ingredient['isBase'] ?? false;
            if ((!$isBase && !isset($referenced[$slug])) || $this->validAdoption($userId, $slug) !== null) {
                continue;
            }
            $existing = null;
            foreach ($this->ingredients as $candidate => $copy) {
                if ($copy['creator'] === $userId && $copy['adoptedFrom'] === $slug && $copy['name'] === $ingredient['name'] && $copy['department'] === $ingredient['department']) {
                    $existing = $candidate;
                    break;
                }
            }
            $result[] = new IngredientToSeed($slug, $ingredient['name'], $isBase, $existing);
        }

        return $result;
    }

    public function copyCatalogIngredients(string $userId, string $ownerId, array $copySlugBySourceSlug): int
    {
        $this->calls[] = 'copyCatalogIngredients';
        foreach ($copySlugBySourceSlug as $source => $copy) {
            $this->ingredients[$copy] = ['name' => $this->ingredients[$source]['name'], 'department' => $this->ingredients[$source]['department'], 'creator' => $userId, 'adoptedFrom' => $source, 'isBase' => false];
        }

        return count($copySlugBySourceSlug);
    }

    private function validAdoption(string $userId, string $common): ?string
    {
        $alias = $this->aliases[$userId][$common] ?? null;
        if ($alias === null || $alias['kind'] !== 'adoption') {
            return null;
        }
        $copy = $this->ingredients[$alias['ingredient']] ?? null;

        return $copy !== null && $copy['creator'] === $userId ? $alias['ingredient'] : null;
    }

    /**
     * @param array<string, string> $map nouveau slug par ancien slug
     */
    private function repoint(string $userId, array $map): AdoptionRepointed
    {
        $recipeRows = $recipes = $listRows = $listIngredients = $lists = 0;
        $corrupted = false;
        foreach ($this->recipes as $id => &$recipe) {
            if ($recipe['author'] !== $userId) {
                continue;
            }
            $touched = false;
            foreach ($recipe['rows'] as &$row) {
                if (isset($map[$row['ingredient']])) {
                    $row['ingredient'] = $map[$row['ingredient']];
                    if ($this->corruptQuantities && !$corrupted) {
                        $row['quantity'] += 1;
                        $corrupted = true;
                    }
                    ++$recipeRows;
                    $touched = true;
                }
            }
            unset($row);
            if ($touched) {
                ++$recipe['version'];
                ++$recipes;
            }
        }
        unset($recipe);
        foreach ($this->lists as $id => &$list) {
            if ($list['owner'] !== $userId) {
                continue;
            }
            $touched = false;
            foreach ($list['rows'] as &$row) {
                if (isset($map[$row['ingredient']])) {
                    $row['ingredient'] = $map[$row['ingredient']];
                    ++$listRows;
                    $touched = true;
                }
            }
            unset($row);
            foreach ($list['ingredients'] as &$row) {
                if (isset($map[$row['ingredient']])) {
                    $row['ingredient'] = $map[$row['ingredient']];
                    ++$listIngredients;
                    $touched = true;
                }
            }
            unset($row);
            if ($touched) {
                ++$list['version'];
                ++$lists;
            }
        }
        unset($list);

        return new AdoptionRepointed($recipeRows, $recipes, $listRows, $listIngredients, $lists);
    }
}
