<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Repas\Repas\Domain\Interface\MarmitonImportLock;
use Repas\Repas\Infrastructure\Marmiton\MarmitonImportPostgreSQLLock;
use Repas\Tests\Helper\DatabaseTestCase;

/**
 * Le verrou d'un import Marmiton (ADR 0037 décision 14) sur la VRAIE base, avec DEUX connexions indépendantes (deux transactions, comme deux
 * requêtes simultanées) : tant que la première tient le verrou d'un identifiant, la seconde l'attend, un autre identifiant ne l'attend pas, et le
 * verrou se relâche au `COMMIT` comme au `ROLLBACK`. La transaction des tests (DAMA) ne tient aucun verrou : les deux connexions sont neuves.
 */
class MarmitonImportLockTest extends DatabaseTestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
        $this->connections = [];

        parent::tearDown();
    }

    public function testTheServiceIsTheAdvisoryLockOfTheDatabase(): void
    {
        $this->assertInstanceOf(MarmitonImportPostgreSQLLock::class, static::getContainer()->get(MarmitonImportLock::class));
    }

    public function testASecondTransactionWaitsForTheFirstOneOnTheSameRecipeAndGetsThroughAfterItsCommit(): void
    {
        // Given : la première transaction tient le verrou de la recette 22082
        $first = $this->transaction();
        new MarmitonImportPostgreSQLLock($first)->acquire('22082');

        // When : la seconde le demande (elle n'attendra que 300 ms pour que le test ne bloque pas)
        $second = $this->transaction();
        $second->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $started = microtime(true);
        try {
            new MarmitonImportPostgreSQLLock($second)->acquire('22082');
            $this->fail('La seconde transaction aurait dû attendre le verrou.');
        } catch (DriverException $exception) {
            // Then : elle a attendu (jusqu'au délai), sans l'obtenir
            $this->assertGreaterThanOrEqual(0.25, microtime(true) - $started);
            $this->assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
        }
        $second->rollBack();

        // When : la première termine
        $first->commit();

        // Then : le verrou est libre, la seconde l'obtient tout de suite
        $second->beginTransaction();
        $second->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $started = microtime(true);
        new MarmitonImportPostgreSQLLock($second)->acquire('22082');
        $this->assertLessThan(0.25, microtime(true) - $started);
        $second->commit();
    }

    public function testTheLockIsAlsoReleasedByARollback(): void
    {
        // Given : un import qui échoue (page illisible, plafond atteint…) annule sa transaction
        $first = $this->transaction();
        new MarmitonImportPostgreSQLLock($first)->acquire('22082');
        $first->rollBack();

        // When & Then : le verrou est libre
        $second = $this->transaction();
        $second->executeStatement("SET LOCAL lock_timeout = '300ms'");
        new MarmitonImportPostgreSQLLock($second)->acquire('22082');
        $this->assertTrue($second->isTransactionActive());
    }

    public function testTwoDifferentRecipesNeverWaitForEachOther(): void
    {
        // Given
        $first = $this->transaction();
        new MarmitonImportPostgreSQLLock($first)->acquire('22082');
        $second = $this->transaction();
        $second->executeStatement("SET LOCAL lock_timeout = '300ms'");

        // When
        $started = microtime(true);
        new MarmitonImportPostgreSQLLock($second)->acquire('22083');

        // Then
        $this->assertLessThan(0.25, microtime(true) - $started);
    }

    public function testAnAcquisitionIsAnOrdinaryQueryInTheCurrentTransactionAndIsReleasedWithIt(): void
    {
        // Given : la connexion de l'application, dans la transaction des tests
        $connection = $this->entityManager->getConnection();
        $before = (int) $connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()");

        // When : un import prend le verrou deux fois (même transaction : il le reprend sans attendre)
        $lock = static::getContainer()->get(MarmitonImportLock::class);
        $lock->acquire('22082');
        $lock->acquire('22082');

        // Then : un seul verrou consultatif de plus, tenu jusqu'à la fin de la transaction
        $this->assertSame($before + 1, (int) $connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
    }

    private function transaction(): Connection
    {
        $params = $this->entityManager->getConnection()->getParams();
        // Une connexion NEUVE, hors de la connexion statique des tests (DAMA) : elle tient et relâche ses verrous vraiment
        unset($params['driverOptions']);
        $connection = DriverManager::getConnection($params);
        $connection->beginTransaction();
        $this->connections[] = $connection;

        return $connection;
    }
}
