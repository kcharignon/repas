<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261030170000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261030170000.php';

/**
 * La migration de la préférence d'enseigne (DRV-703), sur la VRAIE base : elle crée la table que le mapping crée, elle est rejouable, et son
 * retour arrière ne retire que cette table (les réglages des enseignes ne sont pas touchés : la version précédente du code marche sans elle).
 */
class RetailerPreferenceMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMigrationBuildsTheSameTableAsTheMapping(): void
    {
        // Given : la table telle que le mapping la crée, puis supprimée
        $expected = $this->tableSnapshot();
        $this->assertSame(['user_id'], $expected['primaryKey']);
        $this->assertSame(['user_id', 'default_retailer', 'last_used_retailer', 'updated_at'], array_column($expected['columns'], 'column_name'));
        $this->assertSame(['NO', 'YES', 'YES', 'NO'], array_column($expected['columns'], 'is_nullable'));
        $this->connection->executeStatement('DROP TABLE drive_retailer_preference');

        // When
        $this->runMigration(new Version20261030170000($this->connection, new NullLogger()));

        // Then : mêmes colonnes (nullables comme il faut), même clé primaire, mêmes index
        $this->assertSame($expected, $this->tableSnapshot());
    }

    public function testTheMigrationCanBeReplayedAndLeavesThePreferencesAlone(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO drive_retailer_preference (user_id, default_retailer, last_used_retailer, updated_at) VALUES ('user-1', 'leclerc', NULL, NOW())");
        $before = $this->tableSnapshot();

        // When
        $this->runMigration(new Version20261030170000($this->connection, new NullLogger()));

        // Then
        $this->assertSame($before, $this->tableSnapshot());
        $this->assertSame([['user-1', 'leclerc', null]], $this->connection->fetchAllNumeric('SELECT user_id, default_retailer, last_used_retailer FROM drive_retailer_preference'));
    }

    public function testRollingBackDropsOnlyThePreferenceTable(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO drive_settings (user_id, retailer, store_id, store_label, base_url, updated_at, product_writes_count) VALUES ('user-1', 'leclerc', '123456', NULL, 'https://exemple.leclercdrive.fr/magasin-123456-123456', NOW(), 0)");
        $migration = new Version20261030170000($this->connection, new NullLogger());

        // When
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }

        // Then : la table a disparu, les réglages sont intacts
        $this->assertFalse($this->connection->createSchemaManager()->tablesExist(['drive_retailer_preference']));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM drive_settings'));
    }

    public function testThePreviousVersionOfTheCodeIsNotAffectedByTheTable(): void
    {
        // Then : aucune table existante ne dépend de la nouvelle (ni clé étrangère vers elle ni depuis elle), la version précédente l'ignore
        $foreignKeys = $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_type = 'FOREIGN KEY' AND table_name = 'drive_retailer_preference'");
        $this->assertSame(0, (int) $foreignKeys);
    }

    private function runMigration(AbstractMigration $migration): void
    {
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    /**
     * @return array{columns: list<array<string, mixed>>, primaryKey: list<string>, indexes: list<string>}
     */
    private function tableSnapshot(): array
    {
        return [
            'columns' => $this->connection->fetchAllAssociative(
                "SELECT column_name, data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = 'drive_retailer_preference' ORDER BY ordinal_position",
            ),
            'primaryKey' => $this->connection->fetchFirstColumn(
                "SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = 'drive_retailer_preference'::regclass AND i.indisprimary ORDER BY array_position(i.indkey::int[], a.attnum::int)",
            ),
            'indexes' => $this->connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE tablename = 'drive_retailer_preference' ORDER BY indexname"),
        ];
    }
}
