<?php

namespace Repas\Tests\Helper;


/**
 * Les mesures des tests de performance (durées, nombre de requêtes SQL) ne s'affichent que sur demande : `TEST_PERF_REPORT=1 make test`.
 * Par défaut la sortie de `make test` ne contient que les points et le verdict.
 */
final class PerformanceReport
{
    public static function line(string $line): void
    {
        if (getenv('TEST_PERF_REPORT') === '1') {
            fwrite(STDERR, $line);
        }
    }
}
