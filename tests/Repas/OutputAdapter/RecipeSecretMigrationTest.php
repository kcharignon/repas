<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261030210000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261030210000.php';

/**
 * La colonne `recipe.secret` (ADR 0033, REC-001) sur la VRAIE base : la migration crée celle que crée le mapping, se rejoue sans erreur, ne
 * change aucune ligne (toute recette existante reste visible comme avant), se défait sans toucher à rien d'autre, et reste compatible avec le
 * code précédent (ADR 0017 règle 6), qui ne la connaît pas.
 */
class RecipeSecretMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesABooleanColumnNotNullAndFalseByDefault(): void
    {
        $this->assertSame(['boolean', 'NO', 'false'], $this->column());
    }

    public function testTheMigrationBuildsTheSameColumnAsTheMapping(): void
    {
        // Given : la colonne telle que le mapping la crée, puis supprimée
        $expected = $this->column();
        $this->connection->executeStatement('ALTER TABLE recipe DROP COLUMN secret');
        $this->assertNull($this->column());

        // When
        $this->runMigration('up');

        // Then
        $this->assertSame($expected, $this->column());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given : une recette déjà secrète
        $this->insertRecipe('r-1', secret: true);
        $this->insertRecipe('r-2');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM recipe ORDER BY id');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni ligne changée, le choix de l'auteur reste
        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM recipe ORDER BY id'));
        $this->assertSame([true, false], $this->connection->fetchFirstColumn('SELECT secret FROM recipe ORDER BY id'));
    }

    public function testEveryExistingRecipeStaysVisibleAfterTheMigrationAndNoDataIsWritten(): void
    {
        // Given : des recettes existantes, la colonne n'existait pas
        $this->insertRecipe('r-1');
        $this->insertRecipe('r-2');
        $this->connection->executeStatement('ALTER TABLE recipe DROP COLUMN secret');

        // When
        $this->runMigration('up');

        // Then : faux partout, par la seule valeur par défaut (la migration n'écrit aucune donnée)
        $this->assertSame([false, false], $this->connection->fetchFirstColumn('SELECT secret FROM recipe ORDER BY id'));
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheChoice(): void
    {
        // Given : le code précédent n'a pas la colonne dans son entité : son INSERT ne la nomme pas, sa recette naît visible
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version) VALUES ('old-1', 'Crêpes', 'crepes', 4, 'user-1', 'dessert', 1)");
        $this->assertFalse($this->connection->fetchOne("SELECT secret FROM recipe WHERE id = 'old-1'"));
        $this->connection->executeStatement("UPDATE recipe SET secret = TRUE WHERE id = 'old-1'");

        // When : une modification telle que l'écrit l'ancien code
        $this->connection->executeStatement("UPDATE recipe SET name = 'Crêpes sucrées', slug = 'crepes-sucrees', serving = 6 WHERE id = 'old-1'");

        // Then : le choix de l'auteur n'a pas bougé
        $this->assertTrue($this->connection->fetchOne("SELECT secret FROM recipe WHERE id = 'old-1'"));
    }

    public function testRollingBackDropsOnlyTheColumn(): void
    {
        // Given
        $this->insertRecipe('r-1', secret: true);

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : le choix disparaît (la recette redevient visible), la recette reste entière
        $this->assertNull($this->column());
        $this->assertSame('Crêpes', $this->connection->fetchOne("SELECT name FROM recipe WHERE id = 'r-1'"));
    }

    /**
     * @return array{string, string, string|null}|null type, nullable, valeur par défaut ; `null` sans la colonne
     */
    private function column(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'recipe' AND column_name = 'secret'",
        );

        return $row === false ? null : [$row['data_type'], $row['is_nullable'], $row['column_default']];
    }

    private function insertRecipe(string $id, bool $secret = false): void
    {
        $columns = $this->column() === null ? '' : ', secret';
        $values = $this->column() === null ? '' : ', ' . ($secret ? 'TRUE' : 'FALSE');
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version$columns) VALUES ('$id', 'Crêpes', 'crepes', 4, 'user-1', 'dessert', 1$values)");
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261030210000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
