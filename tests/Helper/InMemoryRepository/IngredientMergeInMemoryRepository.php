<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientMergeRepository;
use Repas\Repas\Domain\Model\IngredientMergeRepointed;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\MergeUnitMove;
use Repas\Repas\Domain\Model\UnitFactor;

/**
 * Le port des lectures et des écritures en masse de la fusion de deux ingrédients (ING-005) sur de simples tableaux, pour tester
 * l'orchestration du handler sans base : mêmes règles que `IngredientMergePostgreSQLRepository` (unités où iront les lignes de liste,
 * ligne de l'ingrédient gardé qui reçoit la somme et reste cochée seulement si les deux l'étaient, ligne de l'absorbé qui devient celle du
 * gardé, compteurs arrondis au pas supérieur, versions +1 des seuls agrégats modifiés). Le SQL, lui, est prouvé sur la vraie base
 * (`tests/Repas/OutputAdapter/IngredientMergeRepositoryTest`) avec les mêmes cas.
 *
 * L'état est public : un test le prépare et le relit directement. Une transaction n'existe pas ici : une erreur du handler ne défait rien,
 * c'est le bus de commandes réel qui l'annule (prouvé sur la base).
 */
final class IngredientMergeInMemoryRepository implements IngredientMergeRepository
{
    /** @var array<string, array{author: string, version: int, rows: list<array{ingredient: string, unit: string}>}> par identifiant de recette */
    public array $recipes = [];
    /** @var array<string, array{owner: string, version: int, status: string, rows: list<array{id: string, ingredient: string, quantity: float, unit: string, checked: bool}>, items: list<array{id: string, ingredient: string, quantity: float, unit: string}>}> par identifiant de liste */
    public array $lists = [];
    /** @var list<string> les appels, dans l'ordre */
    public array $calls = [];
    /** Quand vrai, `repoint()` laisse une référence à l'absorbé (écriture concurrente) : `INGREDIENT_MERGE_CONFLICT`. */
    public bool $leaveAReference = false;

    public function lock(string $firstSlug, string $secondSlug): void
    {
        $this->calls[] = 'lock';
    }

    public function usageOf(string $userId, string $absorbedSlug, string $keptSlug, string $keptPurchaseUnitSlug): IngredientMergeUsage
    {
        $this->calls[] = 'usageOf';

        $recipeCount = 0;
        $recipeUnits = [];
        $foreign = false;
        foreach ($this->recipes as $recipe) {
            $units = array_column(array_filter($recipe['rows'], static fn(array $row): bool => $row['ingredient'] === $absorbedSlug), 'unit');
            if ($units === []) {
                continue;
            }
            if ($recipe['author'] !== $userId) {
                $foreign = true;
                continue;
            }
            ++$recipeCount;
            foreach ($units as $unit) {
                $recipeUnits[$unit] = $unit;
            }
        }
        ksort($recipeUnits);

        $listCount = $sentListCount = 0;
        $moves = [];
        foreach ($this->lists as $list) {
            $usesAbsorbed = self::has($list['rows'], $absorbedSlug) || self::has($list['items'], $absorbedSlug);
            if ($usesAbsorbed && $list['owner'] !== $userId) {
                $foreign = true;
                continue;
            }
            if ($list['owner'] !== $userId) {
                continue;
            }
            $usesKept = self::has($list['rows'], $keptSlug) || self::has($list['items'], $keptSlug);
            $listCount += $usesAbsorbed ? 1 : 0;
            $sentListCount += ($usesAbsorbed || $usesKept) && $list['status'] === 'SENT' ? 1 : 0;
            foreach ($list['rows'] as $row) {
                if ($row['ingredient'] === $absorbedSlug) {
                    $to = self::firstRowOf($list['rows'], $keptSlug)['unit'] ?? $keptPurchaseUnitSlug;
                    $moves[$row['unit'] . '>' . $to] = new MergeUnitMove($row['unit'], $to);
                }
            }
            foreach ($list['items'] as $item) {
                if ($item['ingredient'] === $absorbedSlug) {
                    $moves[$item['unit'] . '>' . $keptPurchaseUnitSlug] = new MergeUnitMove($item['unit'], $keptPurchaseUnitSlug);
                }
            }
        }
        ksort($moves);

        return new IngredientMergeUsage($recipeCount, $listCount, array_values($recipeUnits), array_values($moves), $sentListCount, $foreign);
    }

    public function repoint(string $userId, string $absorbedSlug, string $keptSlug, string $keptPurchaseUnitSlug, array $factors): IngredientMergeRepointed
    {
        $this->calls[] = 'repoint';

        $factorOf = static function (string $from, string $to) use ($factors): float {
            if ($from === $to) {
                return 1.0;
            }
            foreach ($factors as $factor) {
                /** @var UnitFactor $factor */
                if ($factor->fromUnitSlug === $from && $factor->toUnitSlug === $to) {
                    return $factor->factor;
                }
            }

            throw IngredientException::mergeConflict();
        };

        $recipes = 0;
        foreach ($this->recipes as $id => $recipe) {
            if ($recipe['author'] !== $userId || !self::has($recipe['rows'], $absorbedSlug)) {
                continue;
            }
            foreach ($recipe['rows'] as $index => $row) {
                if ($row['ingredient'] === $absorbedSlug) {
                    $this->recipes[$id]['rows'][$index]['ingredient'] = $keptSlug;
                }
            }
            ++$this->recipes[$id]['version'];
            ++$recipes;
        }

        $lists = 0;
        foreach ($this->lists as $id => $list) {
            if ($list['owner'] !== $userId || !(self::has($list['rows'], $absorbedSlug) || self::has($list['items'], $absorbedSlug))) {
                continue;
            }
            ++$this->lists[$id]['version'];
            ++$lists;

            // Les lignes : A a déjà sa ligne (la première) qui reçoit la somme, décochée si l'une des deux l'est ; sinon la ligne de B devient celle de A
            $absorbedRows = array_values(array_filter($list['rows'], static fn(array $row): bool => $row['ingredient'] === $absorbedSlug));
            if ($absorbedRows !== []) {
                $keptRow = self::firstRowOf($list['rows'], $keptSlug);
                $target = $keptRow['unit'] ?? $keptPurchaseUnitSlug;
                $quantity = array_sum(array_map(static fn(array $row): float => $row['quantity'] * $factorOf($row['unit'], $target), $absorbedRows));
                $checked = !in_array(false, array_column($absorbedRows, 'checked'), true);
                $absorbedIds = array_column($absorbedRows, 'id');
                if ($keptRow !== null) {
                    $rows = [];
                    foreach ($list['rows'] as $row) {
                        if ($row['id'] === $keptRow['id']) {
                            $row['quantity'] += $quantity;
                            $row['checked'] = $row['checked'] && $checked;
                        }
                        if (!in_array($row['id'], $absorbedIds, true)) {
                            $rows[] = $row;
                        }
                    }
                } else {
                    $keepId = $absorbedIds[0];
                    $rows = [];
                    foreach ($list['rows'] as $row) {
                        if ($row['id'] === $keepId) {
                            $row = ['id' => $keepId, 'ingredient' => $keptSlug, 'quantity' => $quantity, 'unit' => $target, 'checked' => $checked];
                        } elseif (in_array($row['id'], $absorbedIds, true)) {
                            continue;
                        }
                        $rows[] = $row;
                    }
                }
                $this->lists[$id]['rows'] = $rows;
            }

            // Les ingrédients ajoutés à la main : des compteurs de pas entiers dans l'unité d'achat de A, arrondis au pas supérieur
            $absorbedItems = array_values(array_filter($list['items'], static fn(array $item): bool => $item['ingredient'] === $absorbedSlug));
            if ($absorbedItems !== []) {
                $quantity = array_sum(array_map(static fn(array $item): float => max(1.0, ceil($item['quantity'] * $factorOf($item['unit'], $keptPurchaseUnitSlug) - 1e-9)), $absorbedItems));
                $absorbedIds = array_column($absorbedItems, 'id');
                $keptItem = null;
                foreach ($list['items'] as $item) {
                    if ($item['ingredient'] === $keptSlug && $item['unit'] === $keptPurchaseUnitSlug) {
                        $keptItem = $item;
                        break;
                    }
                }
                $items = [];
                foreach ($list['items'] as $item) {
                    if ($keptItem !== null && $item['id'] === $keptItem['id']) {
                        $item['quantity'] += $quantity;
                    } elseif ($keptItem === null && $item['id'] === $absorbedIds[0]) {
                        $item = ['id' => $item['id'], 'ingredient' => $keptSlug, 'quantity' => $quantity, 'unit' => $keptPurchaseUnitSlug];
                    } elseif (in_array($item['id'], $absorbedIds, true)) {
                        continue;
                    }
                    $items[] = $item;
                }
                $this->lists[$id]['items'] = $items;
            }
        }

        if ($this->leaveAReference) {
            throw IngredientException::mergeConflict();
        }

        return new IngredientMergeRepointed($recipes, $lists);
    }

    /**
     * @param list<array{ingredient: string}> $rows
     */
    private static function has(array $rows, string $slug): bool
    {
        return array_any($rows, static fn(array $row): bool => $row['ingredient'] === $slug);
    }

    /**
     * @param list<array{id: string, ingredient: string, unit: string}> $rows
     * @return array{id: string, ingredient: string, unit: string}|null
     */
    private static function firstRowOf(array $rows, string $slug): ?array
    {
        // Comme `ORDER BY id LIMIT 1` : la ligne au plus petit identifiant
        $first = null;
        foreach ($rows as $row) {
            if ($row['ingredient'] === $slug && ($first === null || strcmp($row['id'], $first['id']) < 0)) {
                $first = $row;
            }
        }

        return $first;
    }
}
