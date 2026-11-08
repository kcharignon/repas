<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261108100000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261108100000.php';

/**
 * Les colonnes `recipe.marmiton_id` et `recipe.source_url` et l'unité `pincee` (ADR 0037) sur la VRAIE base : la migration crée celles que
 * crée le mapping, se rejoue sans erreur, ne change aucune ligne, se défait, et reste compatible avec le code précédent (ADR 0017 règle 6),
 * qui ne les connaît pas.
 */
class RecipeMarmitonMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesTwoNullableColumnsAndAPartialUniqueIndex(): void
    {
        $this->assertSame(['character varying', 32, 'YES'], $this->column('marmiton_id'));
        $this->assertSame(['character varying', 255, 'YES'], $this->column('source_url'));
        $this->assertStringContainsString('UNIQUE INDEX', (string) $this->indexDefinition());
        $this->assertStringContainsString('WHERE (marmiton_id IS NOT NULL)', (string) $this->indexDefinition());
    }

    public function testTheMigrationBuildsTheSameColumnsAndIndexAsTheMapping(): void
    {
        // Given : ce que le mapping crée, puis supprimé
        $columns = [$this->column('marmiton_id'), $this->column('source_url')];
        $index = $this->indexDefinition();
        $this->runMigration('down');
        $this->assertNull($this->column('marmiton_id'));
        $this->assertNull($this->column('source_url'));
        $this->assertNull($this->indexDefinition());

        // When
        $this->runMigration('up');

        // Then
        $this->assertSame($columns, [$this->column('marmiton_id'), $this->column('source_url')]);
        $this->assertSame($index, $this->indexDefinition());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRecipe(): void
    {
        // Given : une recette ordinaire et une neutre déjà écrite
        $this->insertRecipe('r-1');
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version, marmiton_id, source_url) VALUES ('r-2', 'Tarte', 'tarte', 6, '00000000-0000-4000-8000-4d41524d4954', 'dessert', 1, '22082', 'https://www.marmiton.org/recettes/recette_tarte_22082.aspx')");
        $before = $this->connection->fetchAllAssociative('SELECT * FROM recipe ORDER BY id');

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : ni erreur, ni ligne changée
        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM recipe ORDER BY id'));
    }

    public function testNoExistingRecipeHasATagOrALinkAfterTheMigration(): void
    {
        // Given : des recettes existantes, les colonnes n'existaient pas
        $this->insertRecipe('r-1');
        $this->insertRecipe('r-2');
        $this->runMigration('down');

        // When
        $this->runMigration('up');

        // Then : vide partout (la migration n'écrit aucune donnée de recette)
        $this->assertSame([null, null], $this->connection->fetchFirstColumn('SELECT marmiton_id FROM recipe ORDER BY id'));
        $this->assertSame([null, null], $this->connection->fetchFirstColumn('SELECT source_url FROM recipe ORDER BY id'));
    }

    public function testTwoRecipesWithoutTagCoexistButAMarmitonTagIsUnique(): void
    {
        // Given : deux recettes sans tag
        $this->insertRecipe('r-1');
        $this->insertRecipe('r-2');
        $this->connection->executeStatement("UPDATE recipe SET marmiton_id = '22082' WHERE id = 'r-1'");

        // When / Then : un même tag sur une autre recette est refusé par la base (une seule neutre par recette Marmiton)
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement("UPDATE recipe SET marmiton_id = '22082' WHERE id = 'r-2'");
    }

    public function testThePincheUnitIsAddedOnceAndAnExistingOneIsLeftAlone(): void
    {
        // Given : une unité « pincee » créée à la main, avec son propre nom
        $this->connection->executeStatement("INSERT INTO unit (slug, name, symbol) VALUES ('pincee', 'pincée maison', 'pm')");

        // When
        $this->runMigration('up');
        $this->runMigration('up');

        // Then : rien n'est écrasé
        $this->assertSame([['pincee', 'pincée maison', 'pm', null, null]], array_map('array_values', $this->connection->fetchAllAssociative("SELECT slug, name, symbol, dimension, base_factor FROM unit WHERE slug = 'pincee'")));
    }

    public function testThePincheUnitIsCreatedLikeTheOtherUnitsWithoutDimension(): void
    {
        // Given : aucune pincée
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM unit WHERE slug = 'pincee'"));

        // When
        $this->runMigration('up');

        // Then : comme la cuillère à café de `unit.json`, sans dimension ni facteur
        $this->assertSame([['pincee', 'pincée', 'pincée', null, null]], array_map('array_values', $this->connection->fetchAllAssociative("SELECT slug, name, symbol, dimension, base_factor FROM unit WHERE slug = 'pincee'")));
    }

    public function testThePreviousCodeStaysCompatibleItNeitherWritesNorErasesTheTagOrTheLink(): void
    {
        // Given : le code précédent n'a pas les colonnes dans son entité : son INSERT ne les nomme pas, sa recette naît sans tag
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version) VALUES ('old-1', 'Crêpes', 'crepes', 4, 'user-1', 'dessert', 1)");
        $this->assertNull($this->connection->fetchOne("SELECT marmiton_id FROM recipe WHERE id = 'old-1'"));
        $this->connection->executeStatement("UPDATE recipe SET marmiton_id = '1', source_url = 'https://www.marmiton.org/recettes/recette_a_1.aspx' WHERE id = 'old-1'");

        // When : une modification telle que l'écrit l'ancien code
        $this->connection->executeStatement("UPDATE recipe SET name = 'Crêpes sucrées', slug = 'crepes-sucrees', serving = 6 WHERE id = 'old-1'");

        // Then : le tag et le lien n'ont pas bougé
        $this->assertSame(['1', 'https://www.marmiton.org/recettes/recette_a_1.aspx'], array_values($this->connection->fetchAssociative("SELECT marmiton_id, source_url FROM recipe WHERE id = 'old-1'")));
    }

    public function testRollingBackDropsOnlyTheColumnsTheIndexAndThePincheUnit(): void
    {
        // Given
        $this->insertRecipe('r-1');
        $this->runMigration('up');
        $this->connection->executeStatement("UPDATE recipe SET marmiton_id = '1' WHERE id = 'r-1'");

        // When
        $this->runMigration('down');
        $this->runMigration('down');

        // Then : le tag disparaît, la recette reste entière, la pincée est retirée
        $this->assertNull($this->column('marmiton_id'));
        $this->assertNull($this->indexDefinition());
        $this->assertSame('Crêpes', $this->connection->fetchOne("SELECT name FROM recipe WHERE id = 'r-1'"));
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM unit WHERE slug = 'pincee'"));
    }

    /**
     * @return array{string, int|null, string}|null type, longueur, nullable ; `null` sans la colonne
     */
    private function column(string $name): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = 'recipe' AND column_name = ?",
            [$name],
        );

        return $row === false ? null : [$row['data_type'], $row['character_maximum_length'], $row['is_nullable']];
    }

    private function indexDefinition(): ?string
    {
        $definition = $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'recipe' AND indexname = 'uniq_recipe_marmiton_id'");

        return $definition === false ? null : (string) $definition;
    }

    private function insertRecipe(string $id): void
    {
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version) VALUES ('$id', 'Crêpes', 'crepes', 4, 'user-1', 'dessert', 1)");
    }

    private function runMigration(string $direction): void
    {
        $migration = new Version20261108100000($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
