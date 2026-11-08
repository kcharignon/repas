<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261108140000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261108140000.php';

/**
 * Les colonnes `ingredient.parent_slug` et `ingredient.parent_ratio` et l'index partiel `(creator, parent_slug)` (ADR 0038, SUB-001) sur la
 * VRAIE base : la migration crée ceux que crée le mapping, se rejoue sans erreur, n'écrit aucune donnée, se défait sans toucher à rien
 * d'autre, et reste compatible avec le code précédent (ADR 0017 règle 6), qui ne les connaît pas.
 */
class IngredientParentMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesTwoNullableColumnsAndAPartialIndexOfTheParts(): void
    {
        $this->assertSame(['character varying', 255, 'YES', 'NULL::character varying'], $this->column('parent_slug'));
        $this->assertSame(['double precision', null, 'YES', null], $this->column('parent_ratio'));
        $this->assertStringContainsString('(creator, parent_slug)', (string) $this->indexDefinition());
        $this->assertStringContainsString('WHERE (parent_slug IS NOT NULL)', (string) $this->indexDefinition());
        $this->assertStringNotContainsString('UNIQUE', (string) $this->indexDefinition());
    }

    public function testTheMigrationBuildsTheSameColumnsAndIndexAsTheMapping(): void
    {
        // Given : ce que le mapping crée, puis supprimé
        $columns = [$this->column('parent_slug'), $this->column('parent_ratio')];
        $index = $this->indexDefinition();
        $this->runMigration('down');
        $this->assertNull($this->column('parent_slug'));
        $this->assertNull($this->column('parent_ratio'));
        $this->assertNull($this->indexDefinition());

        // When
        $this->runMigration('up');

        // Then
        $this->assertSame($columns, [$this->column('parent_slug'), $this->column('parent_ratio')]);
        $this->assertSame($index, $this->indexDefinition());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoIngredient(): void
    {
        // Given : un ingrédient ordinaire et une partie déjà reliée
        $this->insertIngredient('oeuf');
        $this->insertIngredient('jaune');
        $this->connection->executeStatement("UPDATE ingredient SET parent_slug = 'oeuf', parent_ratio = 2 WHERE slug = 'jaune'");
        $before = $this->connection->fetchAllAssociative('SELECT * FROM ingredient ORDER BY slug');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni ligne changée, le lien reste
        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM ingredient ORDER BY slug'));
        $this->assertSame(['oeuf', 2.0], $this->parentOf('jaune'));
    }

    public function testNoExistingIngredientHasAParentAfterTheMigration(): void
    {
        // Given : des ingrédients existants, les colonnes n'existaient pas
        $this->insertIngredient('oeuf');
        $this->insertIngredient('jaune');
        $this->runMigration('down');

        // When
        $this->runMigration('up');

        // Then : rien n'est posé par la migration (les liens se font par l'écran, jamais par une migration : ADR 0038 décision 32)
        $this->assertSame([[null, null], [null, null]], [$this->parentOf('oeuf'), $this->parentOf('jaune')]);
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheParent(): void
    {
        // Given : le code précédent n'a pas les colonnes dans son entité : son INSERT ne les nomme pas, l'ingrédient naît ordinaire
        $this->insertIngredient('jaune');
        $this->assertSame([null, null], $this->parentOf('jaune'));
        $this->connection->executeStatement("UPDATE ingredient SET parent_slug = 'oeuf', parent_ratio = 1.5 WHERE slug = 'jaune'");

        // When : une modification telle que l'écrit l'ancien code (nom, image, rayon, unités)
        $this->connection->executeStatement("UPDATE ingredient SET name = 'Jaune frais', slugged_name = 'jaune-frais', image = 'images/jaune.png', compatible_units = '[\"unite\",\"gramme\"]' WHERE slug = 'jaune'");

        // Then : le lien n'a pas bougé
        $this->assertSame(['oeuf', 1.5], $this->parentOf('jaune'));
    }

    public function testRollingBackDropsOnlyTheIndexAndTheTwoColumns(): void
    {
        // Given
        $this->insertIngredient('jaune');
        $this->connection->executeStatement("UPDATE ingredient SET parent_slug = 'oeuf', parent_ratio = 1 WHERE slug = 'jaune'");

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : le lien disparaît, l'ingrédient reste entier
        $this->assertNull($this->column('parent_slug'));
        $this->assertNull($this->column('parent_ratio'));
        $this->assertNull($this->indexDefinition());
        $this->assertSame('Jaune', $this->connection->fetchOne("SELECT name FROM ingredient WHERE slug = 'jaune'"));
        $this->assertNotNull($this->connection->fetchOne("SELECT indexname FROM pg_indexes WHERE tablename = 'ingredient' AND indexname = 'idx_ingredient_creator'"), 'les autres index restent');
    }

    /**
     * @return array{string, int|null, string, string|null}|null type, longueur, nullable, valeur par défaut ; `null` sans la colonne
     */
    private function column(string $name): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'ingredient' AND column_name = ?",
            [$name],
        );

        return $row === false ? null : [$row['data_type'], $row['character_maximum_length'], $row['is_nullable'], $row['column_default']];
    }

    private function indexDefinition(): ?string
    {
        $definition = $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'ingredient' AND indexname = 'idx_ingredient_parent'");

        return $definition === false ? null : (string) $definition;
    }

    /**
     * @return array{?string, ?float}
     */
    private function parentOf(string $slug): array
    {
        $row = $this->connection->fetchAssociative('SELECT parent_slug, parent_ratio FROM ingredient WHERE slug = ?', [$slug]);

        return [$row['parent_slug'], $row['parent_ratio'] === null ? null : (float) $row['parent_ratio']];
    }

    private function insertIngredient(string $slug): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from)"
            . " VALUES (?, ?, '', ?, 'divers', 'unite', 'unite', 'user-1', '[\"unite\"]', NULL)",
            [$slug, ucfirst($slug), $slug],
        );
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261108140000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
