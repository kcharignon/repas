<?php

namespace Repas\Tests\Repas\WriteBudget;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\WriteBudgetTestCase;

/**
 * Cliquet SQL des use cases d'écriture (ADR 0010 règle 12, roadmap phase 8) : le nombre de requêtes de chaque use case
 * est figé dans `budgets.php`. Un use case qui fait plus de requêtes que son budget est une régression ; un use case qui
 * en fait moins impose de baisser le budget (le fichier ne fait que diminuer).
 *
 * Pour régénérer le fichier après un allègement : `make sql-budget-update`. `SQL_BUDGET_VERBOSE=1` affiche la
 * répartition par table de chaque scénario.
 */
class WriteBudgetTest extends WriteBudgetTestCase
{
    /** @var array<string, int> */
    private static array $measured = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function scenarios(): iterable
    {
        foreach (array_keys(self::loadScenarios()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('scenarios')]
    public function testSqlBudgetIsRespected(string $scenario): void
    {
        // When
        [$count, $byTable] = $this->measure(self::loadScenarios()[$scenario]);

        // Then
        $details = implode(', ', array_map(fn(string $key, int $n): string => "$key ×$n", array_keys($byTable), $byTable));
        if (getenv('SQL_BUDGET_VERBOSE') !== false) {
            fwrite(STDERR, sprintf("%s : %d\n  %s\n", $scenario, $count, $details));
        }
        if (getenv('UPDATE_SQL_BUDGETS') !== false) {
            self::$measured[$scenario] = $count;
            $this->addToAssertionCount(1);

            return;
        }

        $expected = self::loadBudgets()[$scenario] ?? null;
        $this->assertSame(
            $expected,
            $count,
            sprintf(
                "%s : %d requêtes SQL, budget %s. %s\nRépartition : %s",
                $scenario,
                $count,
                $expected ?? 'absent',
                match (true) {
                    $expected === null => 'Budget absent : make sql-budget-update.',
                    $count > $expected => 'Régression : allège le use case.',
                    default => 'Allègement : baisse le budget (make sql-budget-update).',
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

        $budgets = array_replace(self::loadBudgets(), self::$measured);
        ksort($budgets);
        $lines = ["<?php", "", "/**", " * Budgets SQL des use cases d'écriture (voir WriteBudgetTest). Ne fait que diminuer.", " *", " * @return array<string, int>", " */", "return ["];
        foreach ($budgets as $scenario => $budget) {
            $lines[] = sprintf("    '%s' => %d,", $scenario, $budget);
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
     * @return array<string, int>
     */
    private static function loadBudgets(): array
    {
        return require __DIR__ . '/budgets.php';
    }
}
