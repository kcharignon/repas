<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027200000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261027200000.php';

/**
 * Les index des lectures par propriétaire (recettes par auteur, ingrédients par créateur, listes par propriétaire), sur la VRAIE base : la
 * migration crée ceux que crée le mapping, se rejoue sans erreur, se défait sans toucher à rien d'autre, et les requêtes de liste et de
 * quota les utilisent.
 */
class OwnerIndexesMigrationTest extends DatabaseTestCase
{
    private const array INDEXES = [
        'idx_recipe_author' => 'recipe',
        'idx_ingredient_creator' => 'ingredient',
        'idx_shopping_list_owner' => 'shopping_list',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMappingCreatesTheThreeIndexes(): void
    {
        // Then
        $this->assertSame([
            'idx_ingredient_creator' => 'CREATE INDEX idx_ingredient_creator ON public.ingredient USING btree (creator, slugged_name)',
            'idx_recipe_author' => 'CREATE INDEX idx_recipe_author ON public.recipe USING btree (author)',
            'idx_shopping_list_owner' => 'CREATE INDEX idx_shopping_list_owner ON public.shopping_list USING btree (owner, status)',
        ], $this->definitions());
    }

    public function testTheMigrationBuildsTheSameIndexesAsTheMapping(): void
    {
        // Given : les index tels que le mapping les crée, puis supprimés
        $expected = $this->definitions();
        $this->assertCount(3, $expected);
        foreach (array_keys(self::INDEXES) as $name) {
            $this->connection->executeStatement("DROP INDEX $name");
        }
        $this->assertSame([], $this->definitions());

        // When
        $this->runMigration(new Version20261027200000($this->connection, new NullLogger()), 'up');

        // Then
        $this->assertSame($expected, $this->definitions());
    }

    public function testTheMigrationCanBeReplayedAndTouchesNoRow(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO recipe (id, name, slug, serving, author, type, version) VALUES ('r-1', 'Crêpes', 'crepes', 4, 'user-1', 'dessert', 1)");
        $before = $this->definitions();

        // When
        $this->runMigration(new Version20261027200000($this->connection, new NullLogger()), 'up');
        $this->runMigration(new Version20261027200000($this->connection, new NullLogger()), 'up');

        // Then
        $this->assertSame($before, $this->definitions());
        $this->assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM recipe'));
    }

    public function testRollingBackDropsOnlyTheThreeIndexes(): void
    {
        // Given
        $primaryKeys = $this->primaryKeys();
        $this->assertCount(3, $primaryKeys);

        // When
        $this->runMigration(new Version20261027200000($this->connection, new NullLogger()), 'down');

        // Then : les clés primaires restent, les trois index disparaissent
        $this->assertSame([], $this->definitions());
        $this->assertSame($primaryKeys, $this->primaryKeys());
    }

    public function testTheListAndQuotaQueriesUseTheIndexes(): void
    {
        // Given : assez de lignes, réparties entre assez de propriétaires, pour que le planificateur préfère l'index à un parcours de la table
        $this->connection->executeStatement(
            "INSERT INTO recipe (id, name, slug, serving, author, type, version)
             SELECT 'r-' || n, 'recette ' || n, 'recette-' || n, 4, 'user-' || (n % 2000), 'plat', 1 FROM generate_series(1, 20000) AS n",
        );
        $this->connection->executeStatement(
            "INSERT INTO ingredient (slug, name, image, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, slugged_name)
             SELECT 'ingredient-' || n, 'ingredient ' || n, '', 'epicerie', 'gramme', 'gramme', 'user-' || (n % 2000), '[]', 'ingredient-' || n FROM generate_series(1, 20000) AS n",
        );
        $this->connection->executeStatement(
            "INSERT INTO shopping_list (id, owner, created_at, status, version)
             SELECT 'l-' || n, 'user-' || (n % 2000), NOW(), CASE WHEN n % 10 = 0 THEN 'ACTIVE' WHEN n % 10 < 6 THEN 'COMPLETED' ELSE 'SENT' END, 1 FROM generate_series(1, 20000) AS n",
        );
        foreach (self::INDEXES as $table) {
            $this->connection->executeStatement("ANALYZE $table");
        }

        // Then : les lectures par propriétaire et les tests d'existence du N-ième (quotas) lisent par l'index
        $this->assertPlanUses('idx_recipe_author', "SELECT id FROM recipe WHERE author = 'user-7' OFFSET 4 LIMIT 1");
        $this->assertPlanUses('idx_recipe_author', "SELECT id FROM recipe WHERE author = 'user-7'");
        $this->assertPlanUses('idx_ingredient_creator', "SELECT slug FROM ingredient WHERE creator = 'user-7' OFFSET 4 LIMIT 1");
        $this->assertPlanUses('idx_ingredient_creator', "SELECT slug FROM ingredient WHERE creator = 'user-7'");
        $this->assertPlanUses('idx_ingredient_creator', "SELECT slug FROM ingredient WHERE slug = 'ingredient-3' OR (creator = 'user-7' AND slugged_name = 'ingredient-7')");
        $this->assertPlanUses('idx_shopping_list_owner', "SELECT id FROM shopping_list WHERE owner = 'user-7' AND status != 'COMPLETED' OFFSET 2 LIMIT 1");
        $this->assertPlanUses('idx_shopping_list_owner', "SELECT id FROM shopping_list WHERE owner = 'user-7' ORDER BY created_at DESC");
        $this->assertPlanUses('idx_shopping_list_owner', "SELECT id FROM shopping_list WHERE owner = 'user-10' AND status = 'ACTIVE'");
    }

    private function assertPlanUses(string $index, string $sql): void
    {
        $plan = implode("\n", $this->connection->fetchFirstColumn("EXPLAIN (COSTS OFF) $sql"));

        $this->assertStringContainsString($index, $plan, $sql . "\n" . $plan);
    }

    /**
     * @return array<string, string> définition de chaque index des trois tables, par nom
     */
    private function definitions(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT indexname, indexdef FROM pg_indexes WHERE indexname IN (?, ?, ?) ORDER BY indexname',
            array_keys(self::INDEXES),
        );

        return array_map('strval', $rows);
    }

    /**
     * @return array<string, string>
     */
    private function primaryKeys(): array
    {
        return array_map('strval', $this->connection->fetchAllKeyValue(
            "SELECT tablename, indexdef FROM pg_indexes WHERE tablename IN ('recipe', 'ingredient', 'shopping_list') AND indexname LIKE '%_pkey' ORDER BY tablename",
        ));
    }

    private function runMigration(Version20261027200000 $migration, string $direction): void
    {
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }
}
