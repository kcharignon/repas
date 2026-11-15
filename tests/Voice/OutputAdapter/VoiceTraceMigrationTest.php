<?php

namespace Repas\Tests\Voice\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261115100000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261115100000.php';

/**
 * La table `voice_trace` et son index (ADR 0029 décision 14) sur la VRAIE base : la migration crée ce que crée le mapping (hormis la façon de numéroter, une colonne
 * d'identité), se rejoue sans erreur, ne touche à aucune autre table, se défait proprement, et reste compatible avec le code précédent (ADR 0017 règle 6), qui ne la
 * connaît pas.
 */
class VoiceTraceMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMigrationBuildsTheSameColumnsAndIndexAsTheMappingWithAnIdentityNumber(): void
    {
        // Given : ce que le mapping crée, puis supprimé
        $columns = $this->columns();
        $index = $this->indexDefinition();
        $this->assertSame(['id', 'created_at', 'user_id', 'link_id', 'request_type', 'intent', 'session_new', 'outcome', 'decision', 'duration_ms', 'request', 'response', 'failure'], array_keys($columns));
        $this->runMigration('down');
        $this->assertSame([], $this->columns());
        $this->assertNull($this->indexDefinition());

        // When
        $this->runMigration('up');

        // Then : mêmes colonnes (type, taille, nullable), même index ; le numéro est une colonne d'identité
        $this->assertSame($columns, $this->columns());
        $this->assertSame($index, $this->indexDefinition());
        $this->assertSame('YES', $this->connection->fetchOne("SELECT is_identity FROM information_schema.columns WHERE table_name = 'voice_trace' AND column_name = 'id'"));
        $this->assertSame(['id'], $this->connection->fetchFirstColumn("SELECT kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name WHERE tc.table_name = 'voice_trace' AND tc.constraint_type = 'PRIMARY KEY'"));
        $this->assertSame('0', (string) $this->connection->fetchOne("SELECT count(*) FROM information_schema.table_constraints WHERE table_name = 'voice_trace' AND constraint_type = 'FOREIGN KEY'"), 'aucune clé étrangère (ADR 0013)');
    }

    public function testTheNumbersAreGivenByTheDatabaseAndGrow(): void
    {
        // Given : la table telle que la migration la crée
        $this->runMigration('down');
        $this->runMigration('up');

        // When : deux échanges, sans numéro
        $this->insertTrace('LaunchRequest');
        $this->insertTrace('IntentRequest');

        // Then
        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM voice_trace ORDER BY id'));
        $this->assertCount(2, $ids);
        $this->assertGreaterThan($ids[0], $ids[1]);
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given : un échange déjà gardé et une liaison
        $this->insertTrace('LaunchRequest');
        $this->connection->executeStatement("INSERT INTO voice_link (id, user_id, created_at) VALUES ('link-1', 'user-1', now())");
        $trace = $this->connection->fetchAllAssociative('SELECT * FROM voice_trace');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni ligne changée
        $this->assertSame($trace, $this->connection->fetchAllAssociative('SELECT * FROM voice_trace'));
        $this->assertSame('1', (string) $this->connection->fetchOne('SELECT count(*) FROM voice_link'));
    }

    public function testRollingBackDropsOnlyTheIndexAndTheTable(): void
    {
        // Given
        $this->insertTrace('LaunchRequest');
        $this->connection->executeStatement("INSERT INTO voice_link (id, user_id, created_at) VALUES ('link-1', 'user-1', now())");

        // When : deux fois, sans erreur
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : la trace disparaît, la liaison et ses index restent
        $this->assertSame([], $this->columns());
        $this->assertNull($this->indexDefinition());
        $this->assertSame('1', (string) $this->connection->fetchOne('SELECT count(*) FROM voice_link'));
        $this->assertNotNull($this->connection->fetchOne("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_voice_link_user'"));
    }

    public function testThePreviousCodeStaysCompatibleItDoesNotKnowTheTable(): void
    {
        // Given : la table est ajoutée, vide
        $this->assertSame('0', (string) $this->connection->fetchOne('SELECT count(*) FROM voice_trace'));

        // Then : ce que l'ancien code écrit (une liaison, un jeton) ne dépend pas d'elle ; l'ajout est seulement additif
        $this->connection->executeStatement("INSERT INTO voice_link (id, user_id, created_at) VALUES ('link-old', 'user-1', now())");
        $this->assertSame('1', (string) $this->connection->fetchOne("SELECT count(*) FROM voice_link WHERE id = 'link-old'"));
        $this->assertSame('0', (string) $this->connection->fetchOne('SELECT count(*) FROM voice_trace'));
    }

    /**
     * @return array<string, array{string, int|null, string}> type, longueur, nullable ; vide sans la table
     */
    private function columns(): array
    {
        $columns = [];
        $rows = $this->connection->fetchAllAssociative(
            "SELECT column_name, data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = 'voice_trace' ORDER BY ordinal_position",
        );
        foreach ($rows as $row) {
            $columns[$row['column_name']] = [$row['data_type'], $row['character_maximum_length'], $row['is_nullable']];
        }

        return $columns;
    }

    private function indexDefinition(): ?string
    {
        $definition = $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'idx_voice_trace_user'");

        return $definition === false ? null : (string) $definition;
    }

    private function insertTrace(string $requestType): void
    {
        $this->connection->executeStatement(
            "INSERT INTO voice_trace (created_at, request_type, outcome, duration_ms, request, response) VALUES (now(), ?, 'ANSWERED', 1, '{}'::jsonb, '{}'::jsonb)",
            [$requestType],
        );
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261115100000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
