<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261108160000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261108160000.php';

/**
 * La colonne `shopping_list.part_shares` (ADR 0038, SUB-003) sur la VRAIE base : la migration crée celle que crée le mapping (un JSONB facultatif),
 * se rejoue sans erreur, n'écrit aucune donnée (toute liste existante n'a aucun registre), se défait sans toucher à rien d'autre, et reste compatible
 * avec le code précédent (ADR 0017 règle 6), qui ne la connaît pas.
 */
class ShoppingListPartSharesMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesANullableJsonbColumnWithoutDefault(): void
    {
        $this->assertSame(['jsonb', 'YES', null], $this->column());
    }

    public function testTheMigrationBuildsTheSameColumnAsTheMapping(): void
    {
        // Given : la colonne telle que le mapping la crée, puis supprimée
        $expected = $this->column();
        $this->connection->executeStatement('ALTER TABLE shopping_list DROP COLUMN part_shares');
        $this->assertNull($this->column());

        // When
        $this->runMigration('up');

        // Then
        $this->assertSame($expected, $this->column());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given : une liste sans partie, une liste qui a déjà un registre
        $this->insertList('l-1', null);
        $this->insertList('l-2', '{"jaune": {"parent": "oeuf", "quantity": 2, "equivalent": 2}}');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM shopping_list ORDER BY id');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then
        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM shopping_list ORDER BY id'));
        $this->assertNull($this->connection->fetchOne("SELECT part_shares FROM shopping_list WHERE id = 'l-1'") ?: null);
        $this->assertNotNull($this->connection->fetchOne("SELECT part_shares FROM shopping_list WHERE id = 'l-2'"));
    }

    public function testEveryExistingListHasNoRegistryAndNoDataIsWritten(): void
    {
        // Given : des listes existantes, la colonne n'existait pas
        $this->insertList('l-1', null);
        $this->connection->executeStatement('ALTER TABLE shopping_list DROP COLUMN part_shares');

        // When
        $this->runMigration('up');

        // Then : vide partout, la migration n'écrit aucune donnée
        $this->assertSame([null], $this->connection->fetchFirstColumn('SELECT part_shares FROM shopping_list'));
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheRegistry(): void
    {
        // Given : le code précédent n'a pas la colonne dans son entité : son INSERT ne la nomme pas, la liste naît sans registre
        $this->connection->executeStatement("INSERT INTO shopping_list (id, owner, created_at, status, version) VALUES ('old-1', 'u-1', '2026-11-01 10:00:00', 'ACTIVE', 1)");
        $this->assertNull($this->connection->fetchOne("SELECT part_shares FROM shopping_list WHERE id = 'old-1'"));
        $this->connection->executeStatement("UPDATE shopping_list SET part_shares = '{\"jaune\": {\"parent\": \"oeuf\", \"quantity\": 2, \"equivalent\": 2}}' WHERE id = 'old-1'");

        // When : une modification telle que l'écrit l'ancien code (nom, statut, version)
        $this->connection->executeStatement("UPDATE shopping_list SET name = 'Courses', status = 'PAUSED', version = 2 WHERE id = 'old-1'");

        // Then : le registre n'a pas bougé
        $this->assertSame(
            ['jaune' => ['parent' => 'oeuf', 'quantity' => 2, 'equivalent' => 2]],
            json_decode((string) $this->connection->fetchOne("SELECT part_shares FROM shopping_list WHERE id = 'old-1'"), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testRollingBackDropsOnlyTheColumn(): void
    {
        // Given
        $this->insertList('l-1', '{"jaune": {"parent": "oeuf", "quantity": 2, "equivalent": 2}}');

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : les registres disparaissent, la liste reste entière
        $this->assertNull($this->column());
        $this->assertSame('ACTIVE', $this->connection->fetchOne("SELECT status FROM shopping_list WHERE id = 'l-1'"));
        $this->assertNotNull($this->connection->fetchOne("SELECT indexname FROM pg_indexes WHERE tablename = 'shopping_list' AND indexname = 'idx_shopping_list_owner'"), 'les autres index restent');
    }

    /**
     * @return array{string, string, string|null}|null type, nullable, valeur par défaut ; `null` sans la colonne
     */
    private function column(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'shopping_list' AND column_name = 'part_shares'",
        );

        return $row === false ? null : [$row['data_type'], $row['is_nullable'], $row['column_default']];
    }

    private function insertList(string $id, ?string $partShares): void
    {
        $this->connection->executeStatement(
            "INSERT INTO shopping_list (id, owner, created_at, status, version, part_shares) VALUES (?, 'u-1', '2026-11-01 10:00:00', 'ACTIVE', 1, ?)",
            [$id, $partShares],
        );
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261108160000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
