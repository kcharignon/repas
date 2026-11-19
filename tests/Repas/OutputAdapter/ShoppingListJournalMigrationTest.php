<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261119100000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261119100000.php';

/**
 * Le journal des listes de courses (ADR 0041) sur la VRAIE base : la migration crée ce que crée le mapping (cinq colonnes sur `shopping_list_row`, la table
 * `shopping_list_archive` et ses deux index), se rejoue sans erreur, ne touche aucune ligne existante, se défait proprement, et reste compatible avec le code
 * précédent (ADR 0017 règle 6), qui ne connaît ni les colonnes ni la table.
 */
class ShoppingListJournalMigrationTest extends DatabaseTestCase
{
    private const array ROW_COLUMNS = ['checked_by', 'checked_at', 'drive_outcome', 'drive_reason', 'drive_attempted_at'];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMigrationBuildsTheSameColumnsAndIndexesAsTheMapping(): void
    {
        // Given : ce que le mapping crée, puis supprimé
        $rowColumns = $this->rowColumns();
        $archiveColumns = $this->archiveColumns();
        $indexes = $this->archiveIndexes();
        $this->assertSame(self::ROW_COLUMNS, array_keys($rowColumns));
        $this->assertSame(['id', 'shopping_list_id', 'owner_id', 'name', 'status', 'list_type_id', 'list_type_name', 'created_at', 'sent_at', 'version', 'archived_at', 'cause', 'line_count', 'lines', 'meals', 'part_shares'], array_keys($archiveColumns));
        $this->runMigration('down');
        $this->assertSame([], $this->rowColumns());
        $this->assertSame([], $this->archiveColumns());
        $this->assertSame([], $this->archiveIndexes());

        // When
        $this->runMigration('up');

        // Then : mêmes colonnes (type, taille, nullable), mêmes index, aucune clé étrangère (ADR 0013)
        $this->assertSame($rowColumns, $this->rowColumns());
        $this->assertSame($archiveColumns, $this->archiveColumns());
        $this->assertSame($indexes, $this->archiveIndexes());
        $this->assertSame('0', (string) $this->connection->fetchOne("SELECT count(*) FROM information_schema.table_constraints WHERE table_name = 'shopping_list_archive' AND constraint_type = 'FOREIGN KEY'"));
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given : une ligne de liste déjà cochée et une archive
        $this->connection->executeStatement("INSERT INTO shopping_list_row (id, shopping_list, ingredient, quantity, unit, checked) VALUES ('row-1', 'list-1', 'oeuf', 1, 'unite', true)");
        $this->insertArchive('archive-1');
        $row = $this->connection->fetchAssociative('SELECT * FROM shopping_list_row');
        $archive = $this->connection->fetchAssociative('SELECT * FROM shopping_list_archive');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni donnée changée ; une ligne cochée avant l'ADR 0041 n'a pas d'origine devinée
        $this->assertSame($row, $this->connection->fetchAssociative('SELECT * FROM shopping_list_row'));
        $this->assertSame($archive, $this->connection->fetchAssociative('SELECT * FROM shopping_list_archive'));
        $this->assertNull($row['checked_by']);
    }

    public function testRollingBackDropsTheFiveColumnsAndTheArchiveButKeepsTheRows(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO shopping_list_row (id, shopping_list, ingredient, quantity, unit, checked) VALUES ('row-1', 'list-1', 'oeuf', 1, 'unite', true)");

        // When : deux fois, sans erreur
        $this->runMigration('down');
        $this->runMigration('down');

        // Then
        $this->assertSame([], $this->rowColumns());
        $this->assertSame([], $this->archiveColumns());
        $this->assertSame('1', (string) $this->connection->fetchOne('SELECT count(*) FROM shopping_list_row'));
        $this->assertTrue((bool) $this->connection->fetchOne("SELECT checked FROM shopping_list_row WHERE id = 'row-1'"));
    }

    public function testThePreviousCodeStaysCompatibleItDoesNotKnowTheNewColumns(): void
    {
        // Then : l'ancien code écrit une ligne sans connaître les colonnes, qui restent vides ; l'archive est vide
        $this->connection->executeStatement("INSERT INTO shopping_list_row (id, shopping_list, ingredient, quantity, unit, checked) VALUES ('row-old', 'list-1', 'lait', 1, 'unite', false)");
        $row = $this->connection->fetchAssociative("SELECT checked_by, checked_at, drive_outcome, drive_reason, drive_attempted_at FROM shopping_list_row WHERE id = 'row-old'");
        $this->assertSame([null, null, null, null, null], array_values($row));
        $this->assertSame('0', (string) $this->connection->fetchOne('SELECT count(*) FROM shopping_list_archive'));
    }

    /**
     * @return array<string, array{string, int|null, string}> type, longueur, nullable ; vide sans les colonnes
     */
    private function rowColumns(): array
    {
        return $this->columns('shopping_list_row', self::ROW_COLUMNS);
    }

    /**
     * @return array<string, array{string, int|null, string}>
     */
    private function archiveColumns(): array
    {
        return $this->columns('shopping_list_archive');
    }

    /**
     * @param list<string>|null $only
     *
     * @return array<string, array{string, int|null, string}>
     */
    private function columns(string $table, ?array $only = null): array
    {
        $columns = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT column_name, data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position',
            [$table],
        );
        foreach ($rows as $row) {
            if ($only === null || in_array($row['column_name'], $only, true)) {
                $columns[$row['column_name']] = [$row['data_type'], $row['character_maximum_length'], $row['is_nullable']];
            }
        }

        return $columns;
    }

    /**
     * @return array<string, string>
     */
    private function archiveIndexes(): array
    {
        return array_column($this->connection->fetchAllAssociative(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'shopping_list_archive' AND indexname <> 'shopping_list_archive_pkey' ORDER BY indexname",
        ), 'indexdef', 'indexname');
    }

    private function insertArchive(string $id): void
    {
        $this->connection->executeStatement(
            "INSERT INTO shopping_list_archive (id, shopping_list_id, owner_id, status, created_at, version, archived_at, cause, line_count, lines, meals) VALUES (?, 'list-1', 'user-1', 'COMPLETED', now(), 1, now(), 'DELETED_BY_USER', 0, '[]'::jsonb, '[]'::jsonb)",
            [$id],
        );
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261119100000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
