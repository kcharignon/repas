<?php

namespace Repas\Tests\Helper\SqlBudget;


/**
 * Compte les requêtes SQL réellement envoyées à la base (SELECT, INSERT, UPDATE, DELETE), hors contrôle de
 * transaction (SAVEPOINT, COMMIT…). Alimenté par `CountingMiddleware`, uniquement en environnement de test.
 */
final class QueryCounter
{
    /** @var list<string> */
    private array $statements = [];

    public function record(string $sql): void
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|WITH)\b/i', $sql) === 1) {
            $this->statements[] = $sql;
        }
    }

    public function reset(): void
    {
        $this->statements = [];
    }

    public function count(): int
    {
        return count($this->statements);
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * Nombre de requêtes par type et par table, de la plus fréquente à la moins fréquente (ex. `SELECT unit` => 54).
     *
     * @return array<string, int>
     */
    public function byTable(): array
    {
        $counts = [];
        foreach ($this->statements as $sql) {
            $kind = strtoupper(preg_match('/^\s*(\w+)/', $sql, $kind) === 1 ? $kind[1] : '?');
            $table = preg_match('/\b(?:FROM|INTO|UPDATE)\s+"?(\w+)"?/i', $sql, $table) === 1 ? $table[1] : '?';
            $key = $kind . ' ' . $table;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }
}
