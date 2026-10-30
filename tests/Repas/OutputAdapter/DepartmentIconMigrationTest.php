<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261030190000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261030190000.php';

/**
 * La colonne `department.icon` (ING-016 B) sur la VRAIE base : la migration crée celle que crée le mapping, se rejoue sans erreur, ne touche à
 * aucune ligne, se défait sans toucher à rien d'autre, et reste compatible avec le code précédent (ADR 0017 règle 6), qui ne la connaît pas.
 */
class DepartmentIconMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesANullableShortTextColumnNullByDefault(): void
    {
        $this->assertSame(['character varying', 32, 'YES', 'NULL::character varying'], $this->column());
    }

    public function testTheMigrationBuildsTheSameColumnAsTheMapping(): void
    {
        // Given : la colonne telle que le mapping la crée, puis supprimée
        $expected = $this->column();
        $this->connection->executeStatement('ALTER TABLE department DROP COLUMN icon');
        $this->assertNull($this->column());

        // When
        $this->runMigration('up');

        // Then
        $this->assertSame($expected, $this->column());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given : un rayon, une icône déjà réglée
        $this->connection->executeStatement("INSERT INTO department (slug, name, image, icon) VALUES ('surgele', 'Surgelé', 'images/surgele.png', 'snowflake')");
        $before = $this->connection->fetchAllAssociative('SELECT * FROM department ORDER BY slug');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni ligne changée, l'icône réglée reste
        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM department ORDER BY slug'));
        $this->assertSame('snowflake', $this->connection->fetchOne("SELECT icon FROM department WHERE slug = 'surgele'"));
    }

    public function testTheMigrationWritesNoDataEveryExistingDepartmentHasNoIcon(): void
    {
        // Given : des rayons existants, la colonne n'existait pas
        $this->connection->executeStatement("INSERT INTO department (slug, name, image) VALUES ('surgele', 'Surgelé', 'images/surgele.png'), ('conserve', 'Conserve', 'images/conserve.png')");
        $this->connection->executeStatement('ALTER TABLE department DROP COLUMN icon');

        // When
        $this->runMigration('up');

        // Then : rien n'est posé par la migration (les icônes se règlent par l'écran d'administration)
        $this->assertSame([null, null], $this->connection->fetchFirstColumn('SELECT icon FROM department ORDER BY slug'));
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheIcon(): void
    {
        // Given : le code précédent n'a pas la colonne dans son entité : ses INSERT et ses UPDATE ne la nomment pas
        $this->connection->executeStatement("INSERT INTO department (slug, name, image) VALUES ('surgele', 'Surgelé', 'images/surgele.png')");
        $this->assertNull($this->connection->fetchOne("SELECT icon FROM department WHERE slug = 'surgele'"));
        $this->connection->executeStatement("UPDATE department SET icon = 'snowflake' WHERE slug = 'surgele'");

        // When : une modification du nom et de l'image, telle que l'écrit l'ancien code
        $this->connection->executeStatement("UPDATE department SET name = 'Surgelés', image = 'images/surgeles.png' WHERE slug = 'surgele'");

        // Then : l'icône n'a pas bougé
        $this->assertSame('snowflake', $this->connection->fetchOne("SELECT icon FROM department WHERE slug = 'surgele'"));
    }

    public function testRollingBackDropsOnlyTheColumn(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO department (slug, name, image, icon) VALUES ('surgele', 'Surgelé', 'images/surgele.png', 'snowflake')");

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : l'icône disparaît, le rayon reste entier
        $this->assertNull($this->column());
        $this->assertSame(['slug' => 'surgele', 'name' => 'Surgelé', 'image' => 'images/surgele.png'], $this->connection->fetchAssociative('SELECT * FROM department'));
    }

    /**
     * @return array{string, int|null, string, string|null}|null type, longueur, nullable, valeur par défaut (NULL) ; `null` sans la colonne
     */
    private function column(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'department' AND column_name = 'icon'",
        );

        return $row === false ? null : [$row['data_type'], $row['character_maximum_length'], $row['is_nullable'], $row['column_default']];
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261030190000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
