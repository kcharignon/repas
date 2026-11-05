<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261105100000;
use Psr\Log\NullLogger;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261105100000.php';

/**
 * La migration de données du prix commun (DRV-770, amendement de l'ADR 0032), sur la VRAIE base : chaque fiche `drive_product` reçoit le prix
 * (avec sa date) de la ligne de produit ACCEPTÉE la plus récente quand il est plus récent que le sien, jamais dans l'autre sens, sans toucher
 * ni une ligne de compte ni un libellé, et sans changer le schéma.
 */
class ProductPriceMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
        $this->connection->executeStatement('DELETE FROM drive_choice_product');
    }

    public function testASheetTakesThePriceAndTheDateOfTheMostRecentAcceptedLine(): void
    {
        // Given : une fiche à 1,49 € du 1er octobre ; deux comptes l'acceptent, l'un l'a lue à 1,59 € le 12, l'autre à 1,29 € le 18
        $sheet = $this->sheet('1', 'Farine', 149, '2026-10-01 08:00:00');
        $this->line('alice', $sheet, 'ACCEPTED', 'Libellé d\'Alice', 159, '2026-10-12 09:30:00');
        $this->line('bob', $sheet, 'ACCEPTED', 'Libellé de Bob', 129, '2026-10-18 10:00:00');

        // When
        $this->runMigration();

        // Then : le prix et la date viennent de la même ligne, la plus récente
        $this->assertSame([[129, '2026-10-18 10:00:00', 'Farine']], $this->sheets());
    }

    public function testASheetThatIsAlreadyMoreRecentIsLeftAlone(): void
    {
        // Given : la fiche a été relue le 20 octobre, les lignes datent du 12 et du 18
        $sheet = $this->sheet('1', 'Farine', 199, '2026-10-20 08:00:00');
        $this->line('alice', $sheet, 'ACCEPTED', null, 159, '2026-10-12 09:30:00');
        $this->line('bob', $sheet, 'ACCEPTED', null, 129, '2026-10-18 10:00:00');

        // When
        $this->runMigration();

        // Then : jamais un prix plus ancien
        $this->assertSame([[199, '2026-10-20 08:00:00', 'Farine']], $this->sheets());
    }

    public function testASheetWithTheSameDateAsALineIsLeftAlone(): void
    {
        // Given
        $sheet = $this->sheet('1', 'Farine', 199, '2026-10-12 09:30:00');
        $this->line('alice', $sheet, 'ACCEPTED', null, 159, '2026-10-12 09:30:00');

        // When
        $this->runMigration();

        // Then
        $this->assertSame([[199, '2026-10-12 09:30:00', 'Farine']], $this->sheets());
    }

    public function testASheetWithoutPriceOrWithoutDateIsFilled(): void
    {
        // Given : une fiche sans aucun prix, une fiche avec un prix mais sans date
        $none = $this->sheet('1', 'Sans prix', null, null);
        $noDate = $this->sheet('2', 'Sans date', 50, null);
        $this->line('alice', $none, 'ACCEPTED', null, 159, '2026-10-12 09:30:00');
        $this->line('alice', $noDate, 'ACCEPTED', null, 79, '2026-10-13 09:30:00');

        // When
        $this->runMigration();

        // Then
        $this->assertSame([[159, '2026-10-12 09:30:00', 'Sans prix'], [79, '2026-10-13 09:30:00', 'Sans date']], $this->sheets());
    }

    public function testARejectedLineAndALineWithHalfOfAPriceNeverCount(): void
    {
        // Given : un produit refusé avec un prix (ligne abîmée), un prix sans date, une date sans prix
        $rejected = $this->sheet('1', 'Refusé', null, null);
        $half = $this->sheet('2', 'Moitié', 100, '2026-10-01 08:00:00');
        $this->line('alice', $rejected, 'REJECTED', null, 159, '2026-10-12 09:30:00');
        $this->line('alice', $half, 'ACCEPTED', null, 300, null);
        $this->line('bob', $half, 'ACCEPTED', null, null, '2026-10-25 09:30:00');

        // When
        $this->runMigration();

        // Then
        $this->assertSame([[null, null, 'Refusé'], [100, '2026-10-01 08:00:00', 'Moitié']], $this->sheets());
    }

    public function testNoAccountLineAndNoLabelIsTouchedAndTheSchemaIsTheSame(): void
    {
        // Given
        $sheet = $this->sheet('1', 'Farine', 149, '2026-10-01 08:00:00');
        $this->line('alice', $sheet, 'ACCEPTED', 'Libellé d\'Alice', 159, '2026-10-12 09:30:00');
        $this->line('bob', $sheet, 'REJECTED', 'Libellé de Bob', null, null);
        $columns = $this->columns();
        $lines = $this->lines();

        // When
        $this->runMigration();

        // Then : les lignes (libellé, prix obsolète, date) et les colonnes sont inchangés
        $this->assertSame($lines, $this->lines());
        $this->assertSame($columns, $this->columns());
    }

    public function testReplayingChangesNothing(): void
    {
        // Given
        $sheet = $this->sheet('1', 'Farine', 149, '2026-10-01 08:00:00');
        $this->line('alice', $sheet, 'ACCEPTED', null, 159, '2026-10-12 09:30:00');
        $this->runMigration();
        $first = $this->sheets();

        // When
        $this->runMigration();

        // Then
        $this->assertSame([[159, '2026-10-12 09:30:00', 'Farine']], $first);
        $this->assertSame($first, $this->sheets());
    }

    public function testRollingBackDoesNothing(): void
    {
        // Given
        $migration = new Version20261105100000($this->connection, new NullLogger());

        // When
        $migration->down(new Schema());

        // Then : une remise à niveau ne se défait pas
        $this->assertSame([], $migration->getSql());
    }

    private function sheet(string $retailerProductId, string $label, ?int $priceCents, ?string $seenAt): string
    {
        $builder = new ProductBuilder()->withRetailerProductId($retailerProductId)->withLabel($label);
        if ($priceCents !== null) {
            $builder->withPrice($priceCents, seenAt: $seenAt === null ? null : new DateTimeImmutable($seenAt));
        }
        $product = $builder->build();
        static::getContainer()->get(ProductRepository::class)->save($product);
        if ($priceCents !== null && $seenAt === null) {
            $this->connection->executeStatement('UPDATE drive_product SET price_seen_at = NULL WHERE id = ?', [$product->getId()]);
        }

        return $product->getId();
    }

    private function line(string $choiceId, string $productId, string $verdict, ?string $label, ?int $priceCents, ?string $seenAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank, label, price_cents, price_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$choiceId . '-' . substr($productId, 0, 8), $productId, $verdict, $verdict === 'ACCEPTED' ? 1 : null, $label, $priceCents, $seenAt],
        );
    }

    private function runMigration(): void
    {
        $migration = new Version20261105100000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    /**
     * @return list<array{?int, ?string, string}> prix, date du prix et libellé de chaque fiche, par identifiant chez l'enseigne
     */
    private function sheets(): array
    {
        return array_map(
            static fn(array $row): array => [$row[0] === null ? null : (int) $row[0], $row[1], $row[2]],
            $this->connection->fetchAllNumeric('SELECT price_cents, price_seen_at::text, label FROM drive_product ORDER BY retailer_product_id'),
        );
    }

    /**
     * @return list<list<mixed>>
     */
    private function lines(): array
    {
        return $this->connection->fetchAllNumeric('SELECT choice_id, product_id, verdict, rank, label, price_cents, price_seen_at::text FROM drive_choice_product ORDER BY choice_id, product_id');
    }

    /**
     * @return list<list<mixed>>
     */
    private function columns(): array
    {
        return $this->connection->fetchAllNumeric("SELECT table_name, column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name IN ('drive_product', 'drive_choice_product') ORDER BY table_name, column_name");
    }
}
