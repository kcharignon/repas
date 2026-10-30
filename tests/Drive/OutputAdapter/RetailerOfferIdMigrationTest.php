<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261030140000;
use Psr\Log\NullLogger;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261030140000.php';

/**
 * La migration qui ajoute `drive_product.retailer_offer_id` (DRV-721), sur la VRAIE base : la colonne est celle que crée le mapping
 * (`VARCHAR(40)`, nullable, valeur par défaut NULL, comme toute colonne Doctrine nullable), les fiches déjà écrites sont conservées sans offre, et le retour arrière la retire.
 */
class RetailerOfferIdMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMigrationAddsTheColumnThatTheMappingBuilds(): void
    {
        // Given : la colonne telle que le mapping la crée, puis retirée (le schéma d'avant la migration)
        $expected = $this->column();
        $this->assertSame(['character varying', 40, 'YES', 'NULL::character varying'], $expected);
        $this->connection->executeStatement('ALTER TABLE drive_product DROP retailer_offer_id');
        $this->assertNull($this->column());

        // When
        $this->play(fn(Version20261030140000 $migration) => $migration->up(new Schema()));

        // Then
        $this->assertSame($expected, $this->column());
    }

    public function testTheProductsAlreadyWrittenAreKeptWithoutOfferAndTheNaturalKeyStaysTheProduct(): void
    {
        // Given : des fiches écrites avant la migration
        static::getContainer()->get(ProductRepository::class)->saveAll([
            new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine')->build(),
            new ProductBuilder()->withRetailerProductId('2')->withLabel('Sel')->build(),
        ]);
        $this->connection->executeStatement('ALTER TABLE drive_product DROP retailer_offer_id');

        // When
        $this->play(fn(Version20261030140000 $migration) => $migration->up(new Schema()));

        // Then : aucune offre, mêmes lignes ; l'unicité reste `(retailer, store_id, retailer_product_id)`
        $this->assertSame([['1', 'Farine', null], ['2', 'Sel', null]], array_map(array_values(...), $this->connection->fetchAllAssociative('SELECT retailer_product_id, label, retailer_offer_id FROM drive_product ORDER BY retailer_product_id')));
        $this->assertSame(
            'CREATE UNIQUE INDEX uniq_drive_product_key ON public.drive_product USING btree (retailer, store_id, retailer_product_id)',
            $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'uniq_drive_product_key'"),
        );
        $this->assertSame(Retailer::LECLERC->value, $this->connection->fetchOne('SELECT retailer FROM drive_product LIMIT 1'));
    }

    public function testTheDownMigrationDropsTheColumnAndBothDirectionsCanBeReplayed(): void
    {
        // Given : une fiche avec une offre
        static::getContainer()->get(ProductRepository::class)->save(new ProductBuilder()->withOfferId('c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d')->build());

        // When
        $this->play(fn(Version20261030140000 $migration) => $migration->down(new Schema()));

        // Then : la colonne a disparu, la fiche est restée
        $this->assertNull($this->column());
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM drive_product'));

        // When : on la remet
        $this->play(fn(Version20261030140000 $migration) => $migration->up(new Schema()));

        // Then
        $this->assertSame(['character varying', 40, 'YES', 'NULL::character varying'], $this->column());
        $this->assertNull($this->connection->fetchOne('SELECT retailer_offer_id FROM drive_product'));
    }

    /**
     * @param callable(Version20261030140000): mixed $direction
     */
    private function play(callable $direction): void
    {
        $migration = new Version20261030140000($this->connection, new NullLogger());
        $direction($migration);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    /**
     * @return array{string, int, string, ?string}|null type, longueur, nullable, valeur par défaut ; `null` si la colonne n'existe pas
     */
    private function column(): ?array
    {
        $row = $this->connection->fetchAssociative("SELECT data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'drive_product' AND column_name = 'retailer_offer_id' AND table_schema = current_schema()");

        return $row === false ? null : [$row['data_type'], (int) $row['character_maximum_length'], $row['is_nullable'], $row['column_default']];
    }
}
