<?php

namespace Repas\Tests\Shared\Infrastructure\RateLimiter;


use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Les compteurs de débit se lisent et s'écrivent sous verrou : des processus PHP réels, qui partagent le même cache de fichiers et le même
 * magasin de verrous `flock`, demandent des jetons au même instant, et chaque demande compte. Sans verrou, les lectures-écritures
 * simultanées se perdent et le compteur laisse passer bien plus de jetons qu'il n'en a.
 *
 * Les processus sont lancés par `tests/Helper/RateLimiter/concurrent-limiter-worker.php`, prêts avant le signal de départ commun.
 */
class CountersAreAtomicAcrossProcessesTest extends TestCase
{
    private const int PROCESSES = 8;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/repas-limiter-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testACounterOfTwentyTokensAcceptsExactlyTwentyHitsFromEightProcessesAtTheSameTime(): void
    {
        // When : huit processus demandent dix jetons chacun au même instant
        $results = $this->runTogether('tokens', 10);

        // Then : vingt acceptés, soixante refusés, aucun jeton perdu ni compté deux fois
        $this->assertSame(20, array_sum(array_column($results, 'accepted')));
        $this->assertSame(60, array_sum(array_column($results, 'refused')));
    }

    public function testNoMorePasswordComparisonsThanTheLimitStartForTheSameUserAtTheSameTime(): void
    {
        // When : huit processus tentent chacun quatre comparaisons (refusées) pour le même utilisateur, au même instant
        $results = $this->runTogether('password', 4);

        // Then : cinq comparaisons seulement ont eu lieu (le plafond), toutes les autres ont été refusées avant de comparer
        $this->assertSame(5, array_sum(array_column($results, 'compared')));
        $this->assertSame(27, array_sum(array_column($results, 'refused')));
    }

    /**
     * @return list<array<string, int>> le décompte de chaque processus
     */
    private function runTogether(string $scenario, int $requests): array
    {
        $worker = dirname(__DIR__, 3) . '/Helper/RateLimiter/concurrent-limiter-worker.php';
        $processes = [];
        for ($number = 1; $number <= self::PROCESSES; $number++) {
            $process = new Process([PHP_BINARY, $worker, $scenario, $this->directory, (string) $number, (string) $requests]);
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $this->waitUntilAllAreReady($processes);
        touch($this->directory . '/go');

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    /**
     * @param list<Process> $processes
     */
    private function waitUntilAllAreReady(array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(glob($this->directory . '/ready-*') ?: []) < self::PROCESSES) {
            foreach ($processes as $process) {
                if (!$process->isRunning() && $process->getExitCode() !== 0) {
                    $this->fail('Un processus s\'est arrêté avant le départ : ' . $process->getErrorOutput() . $process->getOutput());
                }
            }
            if (microtime(true) > $deadline) {
                $this->fail('Les processus ne sont pas prêts après 30 secondes.');
            }
            usleep(2_000);
        }
    }
}
