<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261108180000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261108180000.php';

/**
 * La table `recipe_component`, ses deux index et la colonne `recipe.component_count` (ADR 0040, COMP-001) sur la VRAIE base : la migration crée ce
 * que crée le mapping, se rejoue sans erreur, n'écrit aucune donnée, se défait sans toucher à rien d'autre, et reste compatible avec le code
 * précédent (ADR 0017 règle 6), qui ne les connaît pas.
 */
class RecipeComponentsMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesTheTableTheTwoIndexesAndTheCountColumnWithoutAnyForeignKey(): void
    {
        $this->assertSame([
            'id' => ['character varying', 36, 'NO', null],
            'recipe' => ['character varying', 36, 'NO', null],
            'component' => ['character varying', 36, 'NO', null],
            'multiplier' => ['double precision', null, 'NO', null],
            'position' => ['integer', null, 'NO', '0'],
        ], $this->tableColumns());
        $this->assertSame(['integer', null, 'NO', '0'], $this->countColumn());
        $this->assertStringContainsString('UNIQUE INDEX uniq_recipe_component', (string) $this->indexDefinition('uniq_recipe_component'));
        $this->assertStringContainsString('(recipe, component)', (string) $this->indexDefinition('uniq_recipe_component'));
        $this->assertStringContainsString('(component)', (string) $this->indexDefinition('idx_recipe_component_component'));
        $this->assertStringNotContainsString('UNIQUE', (string) $this->indexDefinition('idx_recipe_component_component'));
        $this->assertSame('0', (string) $this->connection->fetchOne("SELECT count(*) FROM information_schema.table_constraints WHERE table_name = 'recipe_component' AND constraint_type = 'FOREIGN KEY'"), 'aucune clé étrangère (ADR 0013)');
        $this->assertSame(['id'], $this->connection->fetchFirstColumn("SELECT kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name WHERE tc.table_name = 'recipe_component' AND tc.constraint_type = 'PRIMARY KEY'"));
    }

    public function testTheMigrationBuildsTheSameTableIndexesAndColumnAsTheMapping(): void
    {
        // Given : ce que le mapping crée, puis supprimé
        $columns = $this->tableColumns();
        $count = $this->countColumn();
        $unique = $this->indexDefinition('uniq_recipe_component');
        $index = $this->indexDefinition('idx_recipe_component_component');
        $this->runMigration('down');
        $this->assertSame([], $this->tableColumns());
        $this->assertNull($this->countColumn());
        $this->assertNull($this->indexDefinition('uniq_recipe_component'));
        $this->assertNull($this->indexDefinition('idx_recipe_component_component'));

        // When
        $this->runMigration('up');

        // Then
        $this->assertSame($columns, $this->tableColumns());
        $this->assertSame($count, $this->countColumn());
        $this->assertSame($unique, $this->indexDefinition('uniq_recipe_component'));
        $this->assertSame($index, $this->indexDefinition('idx_recipe_component_component'));
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRecipeNorEdge(): void
    {
        // Given : une recette composée et sa recette utilisée
        $this->insertRecipe('tarte', 1);
        $this->insertRecipe('pate', 0);
        $this->insertEdge('edge-1', 'tarte', 'pate');
        $recipes = $this->connection->fetchAllAssociative('SELECT * FROM recipe ORDER BY id');
        $edges = $this->connection->fetchAllAssociative('SELECT * FROM recipe_component ORDER BY id');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni ligne changée
        $this->assertSame($recipes, $this->connection->fetchAllAssociative('SELECT * FROM recipe ORDER BY id'));
        $this->assertSame($edges, $this->connection->fetchAllAssociative('SELECT * FROM recipe_component ORDER BY id'));
    }

    public function testNoExistingRecipeHasAComponentAfterTheMigration(): void
    {
        // Given : des recettes existantes, la colonne et la table n'existaient pas
        $this->insertRecipe('tarte', 0);
        $this->insertRecipe('pate', 0);
        $this->runMigration('down');

        // When
        $this->runMigration('up');

        // Then : aucune donnée n'est écrite, toute recette vaut zéro composant et la table est vide
        $this->assertSame(['0', '0'], array_map('strval', [
            $this->connection->fetchOne("SELECT count(*) FROM recipe WHERE component_count <> 0"),
            $this->connection->fetchOne('SELECT count(*) FROM recipe_component'),
        ]));
        $this->assertSame('2', (string) $this->connection->fetchOne('SELECT count(*) FROM recipe'));
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheComponents(): void
    {
        // Given : le code précédent n'a pas la colonne dans son entité : son INSERT ne la nomme pas, la recette naît sans composant
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version) VALUES ('old-1', 'Tarte', 'tarte', 4, 'user-1', 'dessert', 1)");
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT component_count FROM recipe WHERE id = 'old-1'"));
        $this->insertEdge('edge-1', 'old-1', 'pate');
        $this->connection->executeStatement("UPDATE recipe SET component_count = 1 WHERE id = 'old-1'");

        // When : une modification telle que l'écrit l'ancien code (nom, personnes, type, secret, favori)
        $this->connection->executeStatement("UPDATE recipe SET name = 'Tarte au citron', slug = 'tarte-au-citron', serving = 6, type = 'plat', secret = TRUE, favorite = TRUE WHERE id = 'old-1'");
        $this->connection->executeStatement("DELETE FROM recipe_row WHERE recipe = 'old-1'");

        // Then : le compte et l'arête n'ont pas bougé
        $this->assertSame(1, (int) $this->connection->fetchOne("SELECT component_count FROM recipe WHERE id = 'old-1'"));
        $this->assertSame('1', (string) $this->connection->fetchOne("SELECT count(*) FROM recipe_component WHERE recipe = 'old-1'"));
    }

    public function testAnEdgeToAMissingRecipeIsAccepted(): void
    {
        // Given & When : aucune clé étrangère, comme partout dans le dépôt
        $this->insertEdge('edge-1', 'gone', 'also-gone');

        // Then
        $this->assertSame('1', (string) $this->connection->fetchOne('SELECT count(*) FROM recipe_component'));
    }

    public function testAPairIsUsedOnlyOnce(): void
    {
        // Given
        $this->insertEdge('edge-1', 'tarte', 'pate');

        // Then : l'index unique `(recipe, component)`
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        // When
        $this->insertEdge('edge-2', 'tarte', 'pate');
    }

    public function testRollingBackDropsOnlyTheIndexesTheTableAndTheColumn(): void
    {
        // Given
        $this->insertRecipe('tarte', 1);
        $this->insertEdge('edge-1', 'tarte', 'pate');

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : les composants disparaissent, la recette reste entière
        $this->assertSame([], $this->tableColumns());
        $this->assertNull($this->countColumn());
        $this->assertNull($this->indexDefinition('uniq_recipe_component'));
        $this->assertNull($this->indexDefinition('idx_recipe_component_component'));
        $this->assertSame('Crêpes', $this->connection->fetchOne("SELECT name FROM recipe WHERE id = 'tarte'"));
        $this->assertNotNull($this->connection->fetchOne("SELECT indexname FROM pg_indexes WHERE tablename = 'recipe' AND indexname = 'idx_recipe_author'"), 'les autres index restent');
    }

    /**
     * @return array<string, array{string, int|null, string, string|null}> type, longueur, nullable, valeur par défaut ; vide sans la table
     */
    private function tableColumns(): array
    {
        $columns = [];
        $rows = $this->connection->fetchAllAssociative(
            "SELECT column_name, data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'recipe_component' ORDER BY ordinal_position",
        );
        foreach ($rows as $row) {
            $columns[$row['column_name']] = [$row['data_type'], $row['character_maximum_length'], $row['is_nullable'], $row['column_default']];
        }

        return $columns;
    }

    /**
     * @return array{string, int|null, string, string|null}|null
     */
    private function countColumn(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'recipe' AND column_name = 'component_count'",
        );

        return $row === false ? null : [$row['data_type'], $row['character_maximum_length'], $row['is_nullable'], $row['column_default']];
    }

    private function indexDefinition(string $name): ?string
    {
        $definition = $this->connection->fetchOne('SELECT indexdef FROM pg_indexes WHERE indexname = ?', [$name]);

        return $definition === false ? null : (string) $definition;
    }

    private function insertRecipe(string $id, int $componentCount): void
    {
        $columns = $this->countColumn() === null ? '' : ', component_count';
        $values = $this->countColumn() === null ? '' : ', ' . $componentCount;
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version$columns) VALUES ('$id', 'Crêpes', 'crepes', 4, 'user-1', 'dessert', 1$values)");
    }

    private function insertEdge(string $id, string $recipe, string $component): void
    {
        $this->connection->executeStatement('INSERT INTO recipe_component (id, recipe, component, multiplier, position) VALUES (?, ?, ?, 2, 0)', [$id, $recipe, $component]);
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261108180000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
