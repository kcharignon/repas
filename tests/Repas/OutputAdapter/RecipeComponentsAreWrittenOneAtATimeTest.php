<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Infrastructure\Lock\RecipeComponentPostgreSQLLock;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\DatabaseTestCase;
use Symfony\Component\Process\Process;

/**
 * Deux écritures de recettes qui se croisent se SUIVENT (ADR 0040 décision 27, COMP-002), avec de VRAIS processus : le vrai noyau, le vrai bus, la vraie
 * base, chacun sa connexion et sa transaction. « A utilise B » et « B utilise A », en même temps : sans verrou, chacune lit l'autre sans composant et
 * les deux passent (A et B se composeraient l'une l'autre, la profondeur 1 est cassée). Avec le verrou de l'auteur, la seconde lit ce que la première
 * vient de valider et est refusée (`RECIPE_COMPONENT_CHAIN`).
 *
 * Pour que l'entrelacement soit le même à chaque essai, le test tient lui-même le verrou de l'auteur dans une transaction ouverte : les deux processus
 * arrivent au verrou et ATTENDENT (on le voit dans `pg_locks`), rien n'est écrit ; puis il le relâche. Les données sont validées pour de bon (les processus
 * ne voient pas la transaction des tests) : elles sont effacées en fin d'essai.
 */
class RecipeComponentsAreWrittenOneAtATimeTest extends DatabaseTestCase
{
    private const string TYPE = 'concurrency-type';

    /** @var list<Connection> */
    private array $connections = [];
    /** @var list<string> */
    private array $recipeIds = [];
    private string $authorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = 'concurrency-author-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $cleanup = $this->independentConnection();
        if ($this->recipeIds !== []) {
            $cleanup->executeStatement('DELETE FROM recipe_component WHERE recipe IN (?, ?)', $this->recipeIds);
            $cleanup->executeStatement('DELETE FROM recipe WHERE id IN (?, ?)', $this->recipeIds);
        }
        $cleanup->executeStatement('DELETE FROM recipe_type WHERE slug = ?', [self::TYPE]);
        foreach ($this->connections as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
        $this->connections = [];

        parent::tearDown();
    }

    public function testTwoCrossedWritesWaitForTheLockOfTheAuthorAndOnlyOneOfThemPasses(): void
    {
        // Given : deux recettes ordinaires de l'auteur, sans composant, validées pour de bon
        $a = UuidGenerator::new();
        $b = UuidGenerator::new();
        $this->seed($a, $b);
        // Le test tient le verrou de l'auteur dans une transaction ouverte
        $holder = $this->independentConnection();
        $holder->beginTransaction();
        new RecipeComponentPostgreSQLLock($holder)->acquire($this->authorId);

        // When : « A utilise B » et « B utilise A » démarrent en même temps
        $one = $this->worker($a, $b);
        $two = $this->worker($b, $a);

        // Then : les deux arrivent au verrou et attendent (une demande de verrou consultatif non accordée chacun), rien n'est écrit
        $this->waitUntilWaitingForTheLock(2);
        $this->assertTrue($one->isRunning() && $two->isRunning(), 'Les deux écritures auraient dû attendre le verrou de l\'auteur.');
        $this->assertSame(0, (int) $holder->fetchOne('SELECT COUNT(*) FROM recipe_component WHERE recipe IN (?, ?)', [$a, $b]));

        // When : le verrou est relâché
        $holder->commit();
        $results = [$this->resultOf($one), $this->resultOf($two)];

        // Then : l'une a passé, l'autre a lu ce que la première venait de valider et est refusée par la profondeur 1 ; une seule arête en base
        usort($results, static fn(array $x, array $y): int => $y['ok'] <=> $x['ok']);
        $this->assertSame([true, false], array_column($results, 'ok'), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(RecipeException::COMPONENT_CHAIN, $results[1]['message']);
        $this->assertSame(1, (int) $holder->fetchOne('SELECT COUNT(*) FROM recipe_component WHERE recipe IN (?, ?)', [$a, $b]));
        $this->assertSame(1, (int) $holder->fetchOne('SELECT COUNT(*) FROM recipe WHERE id IN (?, ?) AND component_count = 1', [$a, $b]));
    }

    public function testWritesOfTwoDifferentAuthorsNeverWaitForEachOther(): void
    {
        // Given : le verrou d'un AUTRE auteur est tenu
        $a = UuidGenerator::new();
        $b = UuidGenerator::new();
        $this->seed($a, $b);
        $holder = $this->independentConnection();
        $holder->beginTransaction();
        new RecipeComponentPostgreSQLLock($holder)->acquire('someone-else');

        // When : l'écriture de l'auteur des recettes
        $process = $this->worker($a, $b);
        $result = $this->resultOf($process);

        // Then : elle ne l'attend pas
        $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(1, (int) $holder->fetchOne('SELECT COUNT(*) FROM recipe_component WHERE recipe = ?', [$a]));
    }

    private function seed(string $a, string $b): void
    {
        $connection = $this->independentConnection();
        $connection->executeStatement('DELETE FROM recipe_type WHERE slug = ?', [self::TYPE]);
        $connection->executeStatement('INSERT INTO recipe_type (slug, name, image, sequence) VALUES (?, ?, ?, ?)', [self::TYPE, 'essai', 'images/essai.svg', 1]);
        foreach ([[$a, 'Recette A'], [$b, 'Recette B']] as [$id, $name]) {
            $connection->executeStatement(
                'INSERT INTO recipe (id, name, slug, serving, author, type, version, secret, favorite, component_count) VALUES (?, ?, ?, 4, ?, ?, 1, false, false, 0)',
                [$id, $name, strtolower(str_replace(' ', '-', $name)), $this->authorId, self::TYPE],
            );
        }
        $this->recipeIds = [$a, $b];
    }

    private function worker(string $recipeId, string $componentId): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Helper/RecipeComponents/concurrent-components-worker.php', $recipeId, $componentId, $this->authorId, self::TYPE],
            env: ['APP_ENV' => 'test'],
        );
        $process->setTimeout(60);
        $process->start();

        return $process;
    }

    /**
     * @return array{ok: bool, message?: string, class?: string}
     */
    private function resultOf(Process $process): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Attend que `$count` processus demandent un verrou consultatif de cette base sans l'obtenir.
     */
    private function waitUntilWaitingForTheLock(int $count): void
    {
        $watcher = $this->independentConnection();
        $deadline = microtime(true) + 30;
        while (true) {
            $waiting = (int) $watcher->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())");
            if ($waiting >= $count) {
                return;
            }
            if (microtime(true) > $deadline) {
                $this->fail("Les $count écritures ne sont pas arrivées au verrou après 30 secondes ($waiting en attente).");
            }
            usleep(20_000);
        }
    }

    /**
     * Une connexion NEUVE, hors de la connexion statique des tests (DAMA) : ses écritures sont validées pour de bon et ses verrous tenus vraiment.
     */
    private function independentConnection(): Connection
    {
        $params = $this->entityManager->getConnection()->getParams();
        unset($params['driverOptions']);
        $connection = DriverManager::getConnection($params);
        $this->connections[] = $connection;

        return $connection;
    }
}
