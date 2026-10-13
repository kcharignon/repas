<?php

namespace Repas\Tests\Repas\WriteBudget;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Cliquet SQL des use cases d'écriture (ADR 0010, roadmap phase 8) : le nombre de requêtes de chaque use case, avec et
 * sans `ModelCache`, est figé dans `budgets.php`. Un use case qui fait plus de requêtes que son budget est une
 * régression ; un use case qui en fait moins impose de baisser le budget (le fichier ne fait que diminuer).
 *
 * Pour régénérer le fichier après un allègement : `UPDATE_SQL_BUDGETS=1 make sql-budget-update`.
 */
class WriteBudgetTest extends WriteBudgetTestCase
{
    /** @var array<string, array<string, int>> */
    private static array $measured = [];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function scenarios(): iterable
    {
        foreach (array_keys(self::loadScenarios()) as $name) {
            yield "$name (cache actif)" => [$name, true];
            yield "$name (sans cache)" => [$name, false];
        }
    }

    #[DataProvider('scenarios')]
    public function testSqlBudgetIsRespected(string $scenario, bool $modelCacheEnabled): void
    {
        // When
        [$count, $byTable] = $this->measure($modelCacheEnabled, self::loadScenarios()[$scenario]);

        // Then
        $mode = $modelCacheEnabled ? 'on' : 'off';
        if (getenv('UPDATE_SQL_BUDGETS') !== false) {
            self::$measured[$scenario][$mode] = $count;
            $this->addToAssertionCount(1);

            return;
        }

        $expected = self::loadBudgets()[$scenario][$mode] ?? null;
        $details = implode(', ', array_map(fn(string $key, int $n): string => "$key ×$n", array_keys($byTable), $byTable));
        $this->assertSame(
            $expected,
            $count,
            sprintf(
                "%s (%s) : %d requêtes SQL, budget %s. %s\nRépartition : %s",
                $scenario,
                $modelCacheEnabled ? 'cache actif' : 'sans cache',
                $count,
                $expected ?? 'absent',
                match (true) {
                    $expected === null => 'Budget absent : UPDATE_SQL_BUDGETS=1 make sql-budget-update.',
                    $count > $expected => 'Régression : allège le use case.',
                    default => 'Allègement : baisse le budget (UPDATE_SQL_BUDGETS=1 make sql-budget-update).',
                },
                $details,
            ),
        );
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (getenv('UPDATE_SQL_BUDGETS') === false || self::$measured === []) {
            return;
        }

        $budgets = array_replace_recursive(self::loadBudgets(), self::$measured);
        ksort($budgets);
        $lines = ["<?php", "", "/**", " * Budgets SQL des use cases d'écriture (voir WriteBudgetTest). Ne fait que diminuer.", " *", " * @return array<string, array{on: int, off: int}>", " */", "return ["];
        foreach ($budgets as $scenario => $modes) {
            $lines[] = sprintf("    '%s' => ['on' => %d, 'off' => %d],", $scenario, $modes['on'], $modes['off']);
        }
        $lines[] = "];";
        file_put_contents(__DIR__ . '/budgets.php', implode("\n", $lines) . "\n");
    }

    /**
     * @return array<string, callable(WriteBudgetTestCase): object>
     */
    private static function loadScenarios(): array
    {
        return require __DIR__ . '/scenarios.php';
    }

    /**
     * @return array<string, array{on: int, off: int}>
     */
    private static function loadBudgets(): array
    {
        return require __DIR__ . '/budgets.php';
    }
}
