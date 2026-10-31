<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261031120000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261031120000.php';

/**
 * Le schéma des types de liste de courses (ADR 0036, LST-002) sur la VRAIE base : la migration crée celui que crée le mapping, se rejoue sans
 * erreur, n'écrit aucune donnée, se défait sans toucher à rien d'autre, et reste compatible avec le code précédent (ADR 0017 règle 6), qui
 * ne connaît ni les deux tables ni les trois colonnes.
 */
class ShoppingListTypesMigrationTest extends DatabaseTestCase
{
    private const array TABLES = ['shopping_list_type', 'department_list_type'];

    /** Les colonnes ajoutées : table => colonne. */
    private const array COLUMNS = [
        'shopping_list' => 'list_type_id',
        'ingredient' => 'list_destination',
        'meal' => 'planned_group_id',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesTheTwoTablesAndTheThreeOptionalColumns(): void
    {
        // Then : colonnes, types, nullabilité
        $this->assertSame([
            'shopping_list_type' => [
                'created_at' => ['timestamp without time zone', 'NO'],
                'id' => ['character varying', 'NO'],
                'name' => ['character varying', 'NO'],
                'owner_id' => ['character varying', 'NO'],
                'slugged_name' => ['character varying', 'NO'],
            ],
            'department_list_type' => [
                'department_slug' => ['character varying', 'NO'],
                'list_type_id' => ['character varying', 'NO'],
                'owner_id' => ['character varying', 'NO'],
            ],
        ], array_map(fn(string $table): array => $this->columnsOf($table), array_combine(self::TABLES, self::TABLES)));
        foreach (self::COLUMNS as $table => $column) {
            $this->assertSame(['character varying', 'YES', 'NULL::character varying'], $this->optionalColumn($table, $column), "$table.$column");
        }
    }

    public function testTheMigrationBuildsTheSameSchemaAsTheMapping(): void
    {
        // Given : le schéma tel que le mapping le crée, puis supprimé
        $tables = array_map(fn(string $table): array => $this->definitionOf($table), array_combine(self::TABLES, self::TABLES));
        $columns = $this->optionalColumns();
        $this->runMigration('down');
        $this->assertSame([], $this->existingTables());
        $this->assertSame([], $this->optionalColumns());

        // When
        $this->runMigration('up');

        // Then : mêmes colonnes, mêmes clés, mêmes index, mêmes colonnes facultatives
        $this->assertSame($tables, array_map(fn(string $table): array => $this->definitionOf($table), array_combine(self::TABLES, self::TABLES)));
        $this->assertSame($columns, $this->optionalColumns());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO shopping_list_type (id, owner_id, name, slugged_name, created_at) VALUES ('t-1', 'user-1', 'Boucherie', 'boucherie', '2026-10-31 10:00:00')");
        $this->connection->executeStatement("INSERT INTO department_list_type (owner_id, department_slug, list_type_id) VALUES ('user-1', 'viandes', 't-1')");
        $this->connection->executeStatement("INSERT INTO shopping_list (id, owner, created_at, status, version, list_type_id) VALUES ('l-1', 'user-1', '2026-10-31 10:00:00', 'ACTIVE', 1, 't-1')");
        $before = [
            $this->connection->fetchAllAssociative('SELECT * FROM shopping_list_type'),
            $this->connection->fetchAllAssociative('SELECT * FROM department_list_type'),
            $this->connection->fetchAllAssociative('SELECT * FROM shopping_list'),
        ];

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni ligne changée
        $this->assertSame($before, [
            $this->connection->fetchAllAssociative('SELECT * FROM shopping_list_type'),
            $this->connection->fetchAllAssociative('SELECT * FROM department_list_type'),
            $this->connection->fetchAllAssociative('SELECT * FROM shopping_list'),
        ]);
    }

    public function testTheMigrationWritesNoDataAndEveryExistingRowKeepsTheNewColumnsEmpty(): void
    {
        // Given : des lignes d'avant la migration
        $this->runMigration('down');
        $this->connection->executeStatement("INSERT INTO shopping_list (id, owner, created_at, status, version) VALUES ('l-1', 'user-1', '2026-10-31 10:00:00', 'ACTIVE', 1)");
        $this->connection->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('m-1', 'l-1', 'r-1', 4)");
        $this->connection->executeStatement("INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, is_base) VALUES ('beurre', 'Beurre', '', 'beurre', 'cremerie', 'gramme', 'gramme', 'user-1', '[]', FALSE)");

        // When
        $this->runMigration('up');

        // Then : aucun type, rien de rattaché, toute colonne ajoutée est vide
        $this->assertSame([0, 0], [(int) $this->connection->fetchOne('SELECT COUNT(*) FROM shopping_list_type'), (int) $this->connection->fetchOne('SELECT COUNT(*) FROM department_list_type')]);
        $this->assertNull($this->connection->fetchOne("SELECT list_type_id FROM shopping_list WHERE id = 'l-1'"));
        $this->assertNull($this->connection->fetchOne("SELECT planned_group_id FROM meal WHERE id = 'm-1'"));
        $this->assertNull($this->connection->fetchOne("SELECT list_destination FROM ingredient WHERE slug = 'beurre'"));
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheNewColumns(): void
    {
        // Given : le code précédent n'a aucune de ces colonnes dans ses entités : ses INSERT ne les nomment pas
        $this->connection->executeStatement("INSERT INTO shopping_list (id, owner, created_at, status, version) VALUES ('old-list', 'user-1', '2026-10-31 10:00:00', 'ACTIVE', 1)");
        $this->connection->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('old-meal', 'old-list', 'r-1', 4)");
        $this->connection->executeStatement("INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, is_base) VALUES ('old-beurre', 'Beurre', '', 'beurre', 'cremerie', 'gramme', 'gramme', 'user-1', '[]', FALSE)");
        $this->assertNull($this->connection->fetchOne("SELECT list_type_id FROM shopping_list WHERE id = 'old-list'"));
        $this->assertNull($this->connection->fetchOne("SELECT planned_group_id FROM meal WHERE id = 'old-meal'"));
        $this->assertNull($this->connection->fetchOne("SELECT list_destination FROM ingredient WHERE slug = 'old-beurre'"));
        $this->connection->executeStatement("UPDATE shopping_list SET list_type_id = 't-1' WHERE id = 'old-list'");
        $this->connection->executeStatement("UPDATE meal SET planned_group_id = 'g-1' WHERE id = 'old-meal'");
        $this->connection->executeStatement("UPDATE ingredient SET list_destination = 'main' WHERE slug = 'old-beurre'");

        // When : les modifications telles que l'ancien code les écrit
        $this->connection->executeStatement("UPDATE shopping_list SET name = 'Courses', status = 'PAUSED' WHERE id = 'old-list'");
        $this->connection->executeStatement("UPDATE meal SET serving = 6 WHERE id = 'old-meal'");
        $this->connection->executeStatement("UPDATE ingredient SET name = 'Beurre doux', slugged_name = 'beurre-doux' WHERE slug = 'old-beurre'");

        // Then : le choix des types, lui, n'a pas bougé
        $this->assertSame('t-1', $this->connection->fetchOne("SELECT list_type_id FROM shopping_list WHERE id = 'old-list'"));
        $this->assertSame('g-1', $this->connection->fetchOne("SELECT planned_group_id FROM meal WHERE id = 'old-meal'"));
        $this->assertSame('main', $this->connection->fetchOne("SELECT list_destination FROM ingredient WHERE slug = 'old-beurre'"));
    }

    public function testRollingBackDropsOnlyTheTwoTablesAndTheThreeColumns(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO shopping_list (id, owner, created_at, status, version, list_type_id) VALUES ('l-1', 'user-1', '2026-10-31 10:00:00', 'ACTIVE', 1, 't-1')");

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : les types et les choix disparaissent, la liste reste entière
        $this->assertSame([], $this->existingTables());
        $this->assertSame([], $this->optionalColumns());
        $this->assertSame('ACTIVE', $this->connection->fetchOne("SELECT status FROM shopping_list WHERE id = 'l-1'"));
    }

    /**
     * @return array<string, array{string, string}> type et nullabilité de chaque colonne
     */
    private function columnsOf(string $table): array
    {
        $columns = [];
        foreach ($this->connection->fetchAllAssociative("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = ? ORDER BY column_name", [$table]) as $row) {
            $columns[$row['column_name']] = [$row['data_type'], $row['is_nullable']];
        }

        return $columns;
    }

    /**
     * @return array{string, string, string|null}|null type, nullabilité et valeur par défaut ; `null` sans la colonne
     */
    private function optionalColumn(string $table, string $column): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column],
        );

        return $row === false ? null : [$row['data_type'], $row['is_nullable'], $row['column_default']];
    }

    /**
     * @return array<string, array{string, string, string|null}> les colonnes ajoutées qui existent
     */
    private function optionalColumns(): array
    {
        $found = [];
        foreach (self::COLUMNS as $table => $column) {
            if (($definition = $this->optionalColumn($table, $column)) !== null) {
                $found["$table.$column"] = $definition;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function existingTables(): array
    {
        return $this->connection->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('shopping_list_type', 'department_list_type') ORDER BY table_name");
    }

    /**
     * @return array{columns: array<string, array{string, string, int|null}>, indexes: list<string>}
     */
    private function definitionOf(string $table): array
    {
        $columns = [];
        foreach ($this->connection->fetchAllAssociative('SELECT column_name, data_type, is_nullable, character_maximum_length FROM information_schema.columns WHERE table_name = ? ORDER BY column_name', [$table]) as $row) {
            $columns[$row['column_name']] = [$row['data_type'], $row['is_nullable'], $row['character_maximum_length']];
        }

        return [
            'columns' => $columns,
            'indexes' => $this->connection->fetchFirstColumn('SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND tablename = ? ORDER BY indexname', ['public', $table]),
        ];
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261031120000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
