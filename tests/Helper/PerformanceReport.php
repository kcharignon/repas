<?php

namespace Repas\Tests\Helper;


/**
 * Les mesures des tests de performance (durées, nombre de requêtes SQL) ne s'affichent que sur demande : `TEST_PERF_REPORT=1 make test`.
 * Par défaut la sortie de `make test` ne contient que les points et le verdict.
 */
final class PerformanceReport
{
    /** Ce que coûte la mesure de la couverture (Xdebug en mode `coverage` ou PCOV) sur une borne de durée, pendant que les processus de ParaTest se partagent les cœurs. */
    private const COVERAGE_FACTOR = 3.0;

    public static function line(string $line): void
    {
        if (getenv('TEST_PERF_REPORT') === '1') {
            fwrite(STDERR, $line);
        }
    }

    /**
     * La borne de durée (en unités du paramètre) d'un test de performance : telle quelle sur une machine qui ne mesure rien, élargie quand la couverture
     * est mesurée (la CI joue la suite en parallèle avec couverture, `make test-ci`). Le nombre de requêtes SQL, lui, n'est jamais élargi.
     */
    public static function allowed(float $bound): float
    {
        return self::isMeasuringCoverage() ? $bound * self::COVERAGE_FACTOR : $bound;
    }

    private static function isMeasuringCoverage(): bool
    {
        if (function_exists('xdebug_info') && in_array('coverage', xdebug_info('mode'), true)) {
            return true;
        }

        return extension_loaded('pcov') && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOLEAN);
    }
}
