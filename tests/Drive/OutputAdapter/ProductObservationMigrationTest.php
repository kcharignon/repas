<?php

namespace Repas\Tests\Drive\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261029100000;
use Psr\Log\NullLogger;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261029100000.php';

/**
 * La migration des valeurs observées par le compte (`label`, `price_cents`, `price_seen_at` sur `drive_choice_product`), sur la VRAIE base :
 * les colonnes (identiques à celles que crée le mapping), puis la recopie unique de la fiche commune vers les lignes existantes.
 */
class ProductObservationMigrationTest extends DatabaseTestCase
{
    private const array COLUMNS = ['label', 'price_cents', 'price_seen_at'];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheMigrationBuildsTheSameColumnsAsTheMapping(): void
    {
        // Given : les colonnes telles que le mapping les crée, puis retirées (le schéma d'avant la migration)
        $expected = $this->columnsSnapshot();
        $this->assertCount(3, $expected);
        $this->dropColumns();

        // When
        $this->runMigration(statementsOfKind: null);

        // Then : mêmes colonnes, mêmes types, toutes nullables
        $this->assertSame($expected, $this->columnsSnapshot());
        $this->assertSame(['YES', 'YES', 'YES'], array_column($expected, 'is_nullable'));
    }

    public function testTheExistingLinesAreFilledOnceFromTheCommonSheet(): void
    {
        // Given : le schéma d'avant, deux comptes qui acceptent le même produit (un prix daté), un produit sans prix, un produit refusé, un produit inconnu de la fiche
        $this->dropColumns();
        $priced = new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine T55 1 kg')->withPrice(149, seenAt: new DateTimeImmutable('2026-10-12 09:30:00'))->build();
        $unpriced = new ProductBuilder()->withRetailerProductId('2')->withLabel('Lait entier 1 l')->build();
        $rejected = new ProductBuilder()->withRetailerProductId('3')->withLabel('Farine complète bio')->withPrice(199)->build();
        static::getContainer()->get(ProductRepository::class)->saveAll([$priced, $unpriced, $rejected]);
        $this->connection->executeStatement('DELETE FROM drive_choice_product');
        $this->connection->executeStatement(
            'INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank) VALUES '
            . "('choice-alice', ?, 'ACCEPTED', 1), ('choice-bob', ?, 'ACCEPTED', 1), ('choice-alice', ?, 'ACCEPTED', 2), ('choice-alice', ?, 'REJECTED', NULL), ('choice-bob', 'sans-fiche', 'ACCEPTED', 2)",
            [$priced->getId(), $priced->getId(), $unpriced->getId(), $rejected->getId()],
        );

        // When
        $this->runMigration(statementsOfKind: null);

        // Then : le libellé pour tout produit connu, le prix et sa date pour les seuls produits acceptés qui en ont un ; rien pour un produit absent de la fiche
        $this->assertSame([
            ['choice-alice', $priced->getId(), 'Farine T55 1 kg', 149, '2026-10-12 09:30:00'],
            ['choice-alice', $unpriced->getId(), 'Lait entier 1 l', null, null],
            ['choice-alice', $rejected->getId(), 'Farine complète bio', null, null],
            ['choice-bob', $priced->getId(), 'Farine T55 1 kg', 149, '2026-10-12 09:30:00'],
            ['choice-bob', 'sans-fiche', null, null, null],
        ], $this->lines());
    }

    public function testTheCopyThenDetachesEveryAccountFromTheCommonSheet(): void
    {
        // Given : la migration a recopié la fiche dans les lignes existantes
        $this->dropColumns();
        $product = new ProductBuilder()->withRetailerProductId('1')->withLabel('Farine T55 1 kg')->withPrice(149, seenAt: new DateTimeImmutable('2026-10-12 09:30:00'))->build();
        static::getContainer()->get(ProductRepository::class)->save($product);
        $this->connection->executeStatement('DELETE FROM drive_choice_product');
        $this->connection->executeStatement("INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank) VALUES ('choice-alice', ?, 'ACCEPTED', 1)", [$product->getId()]);
        $this->runMigration(statementsOfKind: null);

        // When : un autre compte change ensuite la fiche commune
        $this->connection->executeStatement("UPDATE drive_product SET label = 'Autre libellé', price_cents = 1, price_seen_at = '2026-10-29 10:00:00' WHERE id = ?", [$product->getId()]);

        // Then : la ligne du premier compte ne bouge pas
        $this->assertSame([['choice-alice', $product->getId(), 'Farine T55 1 kg', 149, '2026-10-12 09:30:00']], $this->lines());
    }

    public function testALineThatAlreadyHasValuesOfItsOwnIsNeverTouchedAndReplayingChangesNothing(): void
    {
        // Given : le schéma d'après (colonnes présentes) ; une ligne avec ses propres valeurs, une avec un libellé seul, une avec un prix seul, une sans rien
        $product = new ProductBuilder()->withRetailerProductId('1')->withLabel('Libellé de la fiche')->withPrice(149, seenAt: new DateTimeImmutable('2026-10-12 09:30:00'))->build();
        static::getContainer()->get(ProductRepository::class)->save($product);
        $this->connection->executeStatement('DELETE FROM drive_choice_product');
        $this->connection->executeStatement(
            'INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank, label, price_cents, price_seen_at) VALUES '
            . "('own', ?, 'ACCEPTED', 1, 'Libellé du compte', 99, '2026-10-20 08:00:00'),"
            . "('label-only', ?, 'ACCEPTED', 1, 'Libellé seul du compte', NULL, NULL),"
            . "('price-only', ?, 'ACCEPTED', 1, NULL, 79, '2026-10-21 08:00:00'),"
            . "('nothing', ?, 'ACCEPTED', 1, NULL, NULL, NULL)",
            [$product->getId(), $product->getId(), $product->getId(), $product->getId()],
        );

        // When : seule la recopie des données est jouée (les colonnes existent déjà), deux fois
        $this->runMigration(statementsOfKind: 'UPDATE');
        $first = $this->lines();
        $this->runMigration(statementsOfKind: 'UPDATE');

        // Then : ce que le compte a lui-même observé reste intact, ce qui manque est complété depuis la fiche, la seconde passe ne change rien
        $fiche = ['Libellé de la fiche', 149, '2026-10-12 09:30:00'];
        $this->assertSame([
            ['label-only', $product->getId(), 'Libellé seul du compte', 149, '2026-10-12 09:30:00'],
            ['nothing', $product->getId(), ...$fiche],
            ['own', $product->getId(), 'Libellé du compte', 99, '2026-10-20 08:00:00'],
            ['price-only', $product->getId(), 'Libellé de la fiche', 79, '2026-10-21 08:00:00'],
        ], $first);
        $this->assertSame($first, $this->lines());
    }

    public function testRollingBackDropsOnlyTheThreeColumns(): void
    {
        // Given
        $columnsBefore = $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'drive_choice_product' ORDER BY ordinal_position");
        $migration = new Version20261029100000($this->connection, new NullLogger());

        // When
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }

        // Then : le reste de la ligne (contenance comprise) est intact
        $this->assertSame(
            array_values(array_diff($columnsBefore, self::COLUMNS)),
            $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'drive_choice_product' ORDER BY ordinal_position"),
        );
        $this->assertContains('pack_size_value', $columnsBefore);
    }

    public function testTheMigrationCopiesTheValuesOfRowsWrittenByTheCode(): void
    {
        // Given : des lignes écrites par le dépôt (colonnes présentes, valeurs propres) restent telles quelles quand la recopie passe
        $product = new ProductBuilder()->withRetailerProductId('1')->withLabel('Libellé de la fiche')->withPrice(149)->build();
        static::getContainer()->get(ProductRepository::class)->save($product);
        static::getContainer()->get(ChoiceRepository::class)->save(
            new ChoiceBuilder()->withId('choice-1')->withUserId('user-1')->withAccepted([$product->getId()])->withObservation($product->getId(), 'Libellé du compte', 99)->build(),
        );

        // When
        $this->runMigration(statementsOfKind: 'UPDATE');

        // Then
        $found = static::getContainer()->get(ChoiceRepository::class)->findOne('user-1', Retailer::LECLERC, '123456', 'farine');
        $this->assertSame(['Libellé du compte', 99], [$found->getObservation($product->getId())->label, $found->getObservation($product->getId())->priceCents]);
    }

    private function dropColumns(): void
    {
        foreach (self::COLUMNS as $column) {
            $this->connection->executeStatement(sprintf('ALTER TABLE drive_choice_product DROP COLUMN %s', $column));
        }
    }

    /**
     * Joue la migration : toutes ses instructions (`null`), ou seulement celles qui commencent par `$statementsOfKind` (la recopie des données,
     * quand les colonnes existent déjà).
     */
    private function runMigration(?string $statementsOfKind): void
    {
        $migration = new Version20261029100000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            if ($statementsOfKind === null || str_starts_with($query->getStatement(), $statementsOfKind)) {
                $this->connection->executeStatement($query->getStatement(), $query->getParameters());
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columnsSnapshot(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT column_name, data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = 'drive_choice_product' AND column_name IN ('label', 'price_cents', 'price_seen_at') ORDER BY column_name",
        );
    }

    /**
     * @return list<array{string, string, ?string, ?int, ?string}> préférence, produit, libellé, prix et date du prix de chaque ligne
     */
    private function lines(): array
    {
        return array_map(
            static fn(array $row): array => [$row[0], $row[1], $row[2], $row[3] === null ? null : (int) $row[3], $row[4]],
            $this->connection->fetchAllNumeric('SELECT choice_id, product_id, label, price_cents, price_seen_at::text FROM drive_choice_product ORDER BY choice_id, rank, product_id'),
        );
    }
}
