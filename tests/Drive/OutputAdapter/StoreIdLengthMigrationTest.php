<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261030100000;
use Psr\Log\NullLogger;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261030100000.php';

/**
 * La migration qui élargit `store_id` de 20 à 40 caractères (DRV-720), sur la VRAIE base : les trois colonnes sont les mêmes que celles que
 * crée le mapping, les index uniques (`uniq_drive_product_key`, `uniq_drive_choice_user_store_ingredient`) et les données sont conservés,
 * et le retour arrière ne se fait que si aucun identifiant ne dépasse 20 caractères.
 */
class StoreIdLengthMigrationTest extends DatabaseTestCase
{
    private const array TABLES = ['drive_settings', 'drive_product', 'drive_choice'];
    private const string UUID = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMigrationWidensTheThreeColumnsToWhatTheMappingBuilds(): void
    {
        // Given : les colonnes telles que le mapping les crée (40), puis ramenées à 20 (le schéma d'avant la migration)
        $expected = $this->columnLengths();
        $this->assertSame(['drive_choice' => 40, 'drive_product' => 40, 'drive_settings' => 40], $expected);
        $this->narrowColumns();
        $this->assertSame(['drive_choice' => 20, 'drive_product' => 20, 'drive_settings' => 20], $this->columnLengths());

        // When
        $this->runUp();

        // Then : mêmes tables, mêmes longueurs que le mapping, et aucune autre table n'a de colonne `store_id`
        $this->assertSame($expected, $this->columnLengths());
        $this->assertSame(['NO', 'NO', 'NO'], array_values($this->connection->fetchAllKeyValue("SELECT table_name, is_nullable FROM information_schema.columns WHERE column_name = 'store_id' AND table_schema = current_schema() ORDER BY table_name")));
    }

    public function testTheUniqueIndexesAndTheDataAreKeptAndTheNewWidthIsUsable(): void
    {
        // Given : des lignes des trois tables avec des identifiants de magasin courts, puis le schéma d'avant
        $this->saveShortRows();
        $this->narrowColumns();
        $indexes = $this->uniqueIndexes();
        $rows = $this->storeIds();
        $this->assertSame(['uniq_drive_choice_user_store_ingredient', 'uniq_drive_product_key'], array_keys($indexes));

        // When
        $this->runUp();

        // Then : les mêmes index (nom, unicité, colonnes dans le même ordre), les mêmes valeurs
        $this->assertSame($indexes, $this->uniqueIndexes());
        $this->assertSame($rows, $this->storeIds());
        $this->assertSame(['123456', '123456', '123456'], array_column($rows, 0));

        // When / Then : un identifiant de 36 caractères entre maintenant dans les trois tables
        $this->connection->executeStatement('UPDATE drive_settings SET store_id = ?', [self::UUID]);
        $this->connection->executeStatement('UPDATE drive_product SET store_id = ?', [self::UUID]);
        $this->connection->executeStatement('UPDATE drive_choice SET store_id = ?', [self::UUID]);
        $this->assertSame([[self::UUID], [self::UUID], [self::UUID]], $this->storeIds());
    }

    public function testPlayingTheMigrationAgainChangesNothing(): void
    {
        // Given
        $this->saveShortRows();
        $before = [$this->columnLengths(), $this->uniqueIndexes(), $this->storeIds()];

        // When
        $this->runUp();
        $this->runUp();

        // Then
        $this->assertSame($before, [$this->columnLengths(), $this->uniqueIndexes(), $this->storeIds()]);
    }

    public function testRollingBackNarrowsTheColumnsWhenNoIdIsLongerThan20AndKeepsTheData(): void
    {
        // Given : le schéma d'après, avec des identifiants de 6 caractères
        $this->saveShortRows();
        $indexes = $this->uniqueIndexes();
        $rows = $this->storeIds();

        // When
        $this->runDown();

        // Then
        $this->assertSame(['drive_choice' => 20, 'drive_product' => 20, 'drive_settings' => 20], $this->columnLengths());
        $this->assertSame($indexes, $this->uniqueIndexes());
        $this->assertSame($rows, $this->storeIds());
    }

    public function testRollingBackIsRefusedBeforeTheFirstStatementWhenAnIdIsLongerThan20(): void
    {
        // Given : un identifiant de 36 caractères dans UNE seule des trois tables (la dernière que la migration regarde)
        $this->saveShortRows();
        $this->connection->executeStatement('UPDATE drive_choice SET store_id = ?', [self::UUID]);
        $rows = $this->storeIds();
        $migration = new Version20261030100000($this->connection, new NullLogger());

        // When
        try {
            $migration->down(new Schema());
            $this->fail('Le retour arrière aurait dû être refusé.');
        } catch (AbortMigration $e) {
            // Then : le message dit combien de lignes, aucune instruction n'est prête, rien n'a bougé
            $this->assertStringContainsString('1 ligne(s)', $e->getMessage());
            $this->assertStringNotContainsString(self::UUID, $e->getMessage());
            $this->assertSame([], $migration->getSql());
            $this->assertSame(['drive_choice' => 40, 'drive_product' => 40, 'drive_settings' => 40], $this->columnLengths());
            $this->assertSame($rows, $this->storeIds());
        }
    }

    private function saveShortRows(): void
    {
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId('user-1')->build());
        static::getContainer()->get(ProductRepository::class)->save(new ProductBuilder()->withRetailerProductId('1')->build());
        static::getContainer()->get(ChoiceRepository::class)->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['p-a'])->build());
    }

    /**
     * Le schéma d'avant la migration : `store_id` de 20 caractères.
     */
    private function narrowColumns(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement(sprintf('ALTER TABLE %s ALTER store_id TYPE VARCHAR(20)', $table));
        }
    }

    private function runUp(): void
    {
        $migration = new Version20261030100000($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->play($migration);
    }

    private function runDown(): void
    {
        $migration = new Version20261030100000($this->connection, new NullLogger());
        $migration->down(new Schema());
        $this->play($migration);
    }

    private function play(Version20261030100000 $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    /**
     * @return array<string, int> longueur de la colonne `store_id` de chaque table qui en a une
     */
    private function columnLengths(): array
    {
        return array_map('intval', $this->connection->fetchAllKeyValue(
            "SELECT table_name, character_maximum_length FROM information_schema.columns WHERE column_name = 'store_id' AND table_schema = current_schema() ORDER BY table_name",
        ));
    }

    /**
     * @return array<string, array{string, string}> les deux index uniques qui portent `store_id` : leur définition et leur unicité
     */
    private function uniqueIndexes(): array
    {
        $indexes = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT i.indexname, i.indexdef, x.indisunique FROM pg_indexes i JOIN pg_class c ON c.relname = i.indexname JOIN pg_index x ON x.indexrelid = c.oid"
            . " WHERE i.indexname IN ('uniq_drive_product_key', 'uniq_drive_choice_user_store_ingredient') ORDER BY i.indexname",
        ) as $row) {
            $indexes[$row['indexname']] = [$row['indexdef'], (string) $row['indisunique']];
        }

        return $indexes;
    }

    /**
     * @return list<array{string}> l'identifiant de magasin de la seule ligne de chaque table, dans l'ordre réglage, produit, préférence
     */
    private function storeIds(): array
    {
        return [
            [(string) $this->connection->fetchOne('SELECT store_id FROM drive_settings')],
            [(string) $this->connection->fetchOne('SELECT store_id FROM drive_product')],
            [(string) $this->connection->fetchOne('SELECT store_id FROM drive_choice')],
        ];
    }
}
