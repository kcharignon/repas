<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261031160000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261031160000.php';

/**
 * La colonne `meal.placed_ingredients` (ADR 0036, LST-005) sur la VRAIE base : la migration crée celle que crée le mapping (un JSONB facultatif),
 * se rejoue sans erreur, n'écrit aucune donnée (tout repas existant garde « toute la recette »), se défait sans toucher à rien d'autre, et reste
 * compatible avec le code précédent (ADR 0017 règle 6), qui ne la connaît pas.
 */
class MealPlacedIngredientsMigrationTest extends DatabaseTestCase
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
        $this->connection->executeStatement('ALTER TABLE meal DROP COLUMN placed_ingredients');
        $this->assertNull($this->column());

        // When
        $this->runMigration('up');

        // Then
        $this->assertSame($expected, $this->column());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given : un repas éclaté, un repas qui a posé toute la recette
        $this->connection->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving, planned_group_id, placed_ingredients) VALUES ('m-1', 'l-1', 'r-1', 4, 'g-1', '[\"lardon\"]')");
        $this->connection->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('m-2', 'l-2', 'r-1', 4)");
        $before = $this->connection->fetchAllAssociative('SELECT * FROM meal ORDER BY id');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then
        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM meal ORDER BY id'));
        $this->assertSame(['["lardon"]', null], $this->connection->fetchFirstColumn('SELECT placed_ingredients FROM meal ORDER BY id'));
    }

    public function testEveryExistingMealStaysAWholeRecipeAndNoDataIsWritten(): void
    {
        // Given : des repas existants, la colonne n'existait pas
        $this->connection->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving) VALUES ('m-1', 'l-1', 'r-1', 4)");
        $this->connection->executeStatement('ALTER TABLE meal DROP COLUMN placed_ingredients');

        // When
        $this->runMigration('up');

        // Then : vide partout, la migration n'écrit aucune donnée
        $this->assertSame([null], $this->connection->fetchFirstColumn('SELECT placed_ingredients FROM meal'));
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheChoice(): void
    {
        // Given : le code précédent n'a pas la colonne dans son entité : son INSERT ne la nomme pas
        $this->connection->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving, planned_group_id) VALUES ('old-1', 'l-1', 'r-1', 4, NULL)");
        $this->assertNull($this->connection->fetchOne("SELECT placed_ingredients FROM meal WHERE id = 'old-1'"));
        $this->connection->executeStatement("UPDATE meal SET placed_ingredients = '[\"lardon\"]' WHERE id = 'old-1'");

        // When : une modification telle que l'écrit l'ancien code (le nombre de personnes)
        $this->connection->executeStatement("UPDATE meal SET serving = 6 WHERE id = 'old-1'");

        // Then : la répartition n'a pas bougé
        $this->assertSame('["lardon"]', $this->connection->fetchOne("SELECT placed_ingredients FROM meal WHERE id = 'old-1'"));
    }

    public function testRollingBackDropsOnlyTheColumn(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO meal (id, shopping_list, recipe, serving, placed_ingredients) VALUES ('m-1', 'l-1', 'r-1', 4, '[\"lardon\"]')");

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : la répartition disparaît, le repas reste entier
        $this->assertNull($this->column());
        $this->assertSame(4, $this->connection->fetchOne("SELECT serving FROM meal WHERE id = 'm-1'"));
    }

    /**
     * @return array{string, string, string|null}|null type, nullable, valeur par défaut ; `null` sans la colonne
     */
    private function column(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'meal' AND column_name = 'placed_ingredients'",
        );

        return $row === false ? null : [$row['data_type'], $row['is_nullable'], $row['column_default']];
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261031160000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
