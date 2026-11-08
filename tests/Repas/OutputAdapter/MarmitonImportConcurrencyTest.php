<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Marmiton\MarmitonImportPostgreSQLLock;
use Repas\Tests\Helper\DatabaseTestCase;
use Symfony\Component\Process\Process;

/**
 * Deux imports du même lien neuf, en même temps (ADR 0037 décision 14, MAR-003) : un VRAI processus d'import attend, sur la vraie base, le
 * verrou que tient une autre transaction (le « premier import »), puis voit la recette neutre que celle-ci a créée au lieu d'en créer une
 * deuxième : une seule neutre, aucun ingrédient orphelin, aucune page lue par le retardataire.
 *
 * Le processus écrit pour de bon dans la base de test (la transaction du test ne l'enveloppe pas) : tout est effacé à la fin, et avant.
 */
class MarmitonImportConcurrencyTest extends DatabaseTestCase
{
    private const string OWNER = Recipe::MARMITON_OWNER_ID;
    private const string TABLES = 'recipe_row, recipe, conversion, ingredient, unit, department, recipe_type, "user"';

    /** @var list<Connection> */
    private array $opened = [];
    private ?Process $process = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->process?->isRunning()) {
            $this->process->stop(0);
        }
        foreach ($this->opened as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        $this->clean();
        foreach ($this->opened as $connection) {
            $connection->close();
        }
        $this->opened = [];

        parent::tearDown();
    }

    public function testALateImportWaitsForTheFirstOneThenCopiesItsNeutralWithoutReadingAnythingOrCreatingAnOrphan(): void
    {
        // Given : un premier import tient le verrou de la recette 22082 dans sa transaction
        $first = $this->connection();
        $first->beginTransaction();
        new MarmitonImportPostgreSQLLock($first)->acquire('22082');

        // When : un second import démarre (un vrai processus) et se bloque sur ce verrou
        $this->process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Helper/Marmiton/concurrent-import-worker.php', 'late-user', 'https://www.marmiton.org/recettes/recette_tarte_22082.aspx'], env: ['APP_ENV' => 'test']);
        $this->process->setTimeout(60);
        $this->process->start();
        $this->waitUntilAnImportWaitsForTheLock();
        $this->assertTrue($this->process->isRunning(), 'Le second import aurait dû attendre : ' . $this->process->getErrorOutput() . $this->process->getOutput());

        // When : le premier crée sa neutre (et son ingrédient), puis termine
        $first->executeStatement("INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from, is_base) VALUES ('oeuf-premier', 'Oeuf', '', 'oeuf', 'divers', 'unite', 'unite', ?, '[\"unite\"]', NULL, FALSE)", [self::OWNER]);
        $first->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version, marmiton_id, source_url) VALUES ('neutre-du-premier', 'Tarte', 'tarte', 4, ?, 'plat', 1, '22082', 'https://www.marmiton.org/recettes/recette_tarte_22082.aspx')", [self::OWNER]);
        $first->executeStatement("INSERT INTO recipe_row (id, ingredient, quantity, unit, recipe, position) VALUES ('ligne-1', 'oeuf-premier', 1, 'unite', 'neutre-du-premier', 0)");
        $first->commit();
        $this->process->wait();

        // Then : le retardataire s'est terminé bien, a copié la neutre du premier sans lire de page
        $this->assertSame(0, $this->process->getExitCode(), $this->process->getErrorOutput() . $this->process->getOutput());
        $result = json_decode($this->process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['created']);
        $this->assertSame(0, $result['reads'], 'Le retardataire n\'aurait pas dû lire la page.');

        // Then : une seule neutre, celle du premier ; la copie du retardataire en descend ; un seul ingrédient du propriétaire réservé (celui du premier)
        $connection = $this->connection();
        $this->assertSame(['neutre-du-premier'], $connection->fetchFirstColumn('SELECT id FROM recipe WHERE marmiton_id = ?', ['22082']));
        $this->assertSame(['neutre-du-premier'], $connection->fetchFirstColumn('SELECT orignal FROM recipe WHERE author = ?', ['late-user']));
        $this->assertSame(['oeuf-premier'], $connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ?', [self::OWNER]));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM recipe'));
        // Et chaque ingrédient du propriétaire réservé est utilisé par la neutre : aucun orphelin
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient i WHERE i.creator = ? AND NOT EXISTS (SELECT 1 FROM recipe_row rr WHERE rr.ingredient = i.slug)', [self::OWNER]));
    }

    /**
     * Observe la base depuis une connexion à part, en commit automatique : dans une transaction, les vues `pg_stat_*` sont figées à la première lecture.
     */
    private function waitUntilAnImportWaitsForTheLock(): void
    {
        $observer = $this->connection();
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                $this->fail('Le second import s\'est arrêté avant d\'attendre : ' . $this->process->getErrorOutput() . $this->process->getOutput());
            }
            if ((int) $observer->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND wait_event = 'advisory' AND datname = current_database()") > 0) {
                return;
            }
            usleep(20_000);
        }
        $this->fail('Le second import n\'a pas attendu le verrou après 30 secondes : ' . $this->process->getErrorOutput() . $this->process->getOutput());
    }

    private function connection(): Connection
    {
        // Une connexion NEUVE, hors de la connexion statique des tests (DAMA) : ses écritures et ses verrous sont réels
        $params = $this->entityManager->getConnection()->getParams();
        unset($params['driverOptions']);
        $connection = DriverManager::getConnection($params);
        $this->opened[] = $connection;

        return $connection;
    }

    private function clean(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('TRUNCATE ' . self::TABLES);
    }
}
