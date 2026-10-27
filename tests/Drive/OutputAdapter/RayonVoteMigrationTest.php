<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261027180000;
use DoctrineMigrations\Version20261027180100;
use Psr\Log\NullLogger;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\RayonVote;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261027180000.php';
require_once dirname(__DIR__, 3) . '/migrations/Version20261027180100.php';

/**
 * Les deux migrations des votes de rayons, sur la VRAIE base : la table (identique à celle que crée le mapping), puis les votes de départ
 * relus des choix réellement enregistrés par les comptes.
 */
class RayonVoteMigrationTest extends DatabaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheTableMigrationBuildsTheSameTableAsTheMapping(): void
    {
        // Given : la table telle que le mapping la crée, puis supprimée
        $expected = $this->tableSnapshot();
        $this->assertNotSame([], $expected['columns']);
        $this->connection->executeStatement('DROP TABLE drive_rayon_vote');

        // When
        $this->runMigration(new Version20261027180000($this->connection, new NullLogger()));

        // Then : mêmes colonnes, même clé primaire (le compte d'abord), mêmes index
        $this->assertSame($expected, $this->tableSnapshot());
        $this->assertSame(['user_id', 'retailer', 'department_slug', 'rayon_id'], $expected['primaryKey']);
    }

    public function testTheTableMigrationCanBeReplayedAndLeavesTheVotesAlone(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO drive_rayon_vote (user_id, retailer, department_slug, rayon_id) VALUES ('user-1', 'leclerc', 'boulangerie', 4001)");
        $before = $this->tableSnapshot();

        // When
        $this->runMigration(new Version20261027180000($this->connection, new NullLogger()));

        // Then
        $this->assertSame($before, $this->tableSnapshot());
        $this->assertSame([['user-1', 'boulangerie', 4001]], $this->votes());
    }

    public function testRollingBackTheTableMigrationDropsOnlyTheNewTableAndNotTheOldCounters(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO drive_department_rayon (retailer, department_slug, rayon_id, chosen_count) VALUES ('leclerc', 'boulangerie', 4001, 3)");
        $migration = new Version20261027180000($this->connection, new NullLogger());

        // When
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }

        // Then
        $this->assertFalse($this->connection->createSchemaManager()->tablesExist(['drive_rayon_vote']));
        $this->assertSame(3, (int) $this->connection->fetchOne("SELECT chosen_count FROM drive_department_rayon WHERE rayon_id = 4001"));
    }

    public function testTheStartingVotesAreReadFromTheChoicesTheAccountsReallyMade(): void
    {
        // Given : Alice a deux ingrédients du rayon de Repas « boulangerie » et un du rayon « divers », Bob un ingrédient « boulangerie »
        $alice = new UserBuilder()->build();
        $bob = new UserBuilder()->build();
        $persister = new DataPersister(static::getContainer());
        $flour = new IngredientBuilder()->isFlour()->withName('Farine d\'Alice')->withSlug('farine-alice')->withCreator($alice)->build();
        $semolina = new IngredientBuilder()->isFlour()->withName('Semoule d\'Alice')->withSlug('semoule-alice')->withCreator($alice)->build();
        $milk = new IngredientBuilder()->isMilk()->withName('Lait d\'Alice')->withSlug('lait-alice')->withCreator($alice)->build();
        $bobFlour = new IngredientBuilder()->isFlour()->withName('Farine de Bob')->withSlug('farine-bob')->withCreator($bob)->build();
        $bobButter = new IngredientBuilder()->isButter()->withName('Beurre de Bob')->withSlug('beurre-bob')->withCreator($bob)->build();
        foreach ([$flour, $semolina, $milk, $bobFlour, $bobButter] as $ingredient) {
            $persister->saveIngredient($ingredient);
        }
        $bakery = $this->departmentOf($flour);
        $misc = $this->departmentOf($milk);

        $products = [];
        foreach ([['1', 4001], ['2', 4001], ['3', 4002], ['4', 4003], ['5', null], ['6', 4004]] as [$id, $rayon]) {
            $products[$id] = new ProductBuilder()->withRetailerProductId($id)->withRayon($rayon)->build();
        }
        static::getContainer()->get(ProductRepository::class)->saveAll(array_values($products));
        $id = static fn(string $retailerProductId): string => $products[$retailerProductId]->getId();
        static::getContainer()->get(ChoiceRepository::class)->saveAll([
            // Alice : deux ingrédients du même rayon de Repas dans le même rayon de l'enseigne, un refus d'un produit d'un autre rayon
            new ChoiceBuilder()->withUserId($alice->getId())->withIngredientSlug('farine-alice')->withAccepted([$id('1')])->withRejected([$id('4')])->build(),
            new ChoiceBuilder()->withUserId($alice->getId())->withIngredientSlug('semoule-alice')->withAccepted([$id('2')])->build(),
            // Un produit sans rayon ne vote pas
            new ChoiceBuilder()->withUserId($alice->getId())->withIngredientSlug('lait-alice')->withAccepted([$id('3'), $id('5')])->build(),
            // Un choix sur l'ingrédient d'un autre compte ne vote pas
            new ChoiceBuilder()->withUserId($alice->getId())->withIngredientSlug('beurre-bob')->withAccepted([$id('6')])->build(),
            // Bob : le même couple qu'Alice, il pèse de son côté
            new ChoiceBuilder()->withUserId($bob->getId())->withIngredientSlug('farine-bob')->withAccepted([$id('1')])->build(),
            // Un choix sur un ingrédient qui n'existe plus ne vote pas
            new ChoiceBuilder()->withUserId($bob->getId())->withIngredientSlug('introuvable')->withAccepted([$id('3')])->build(),
        ]);
        // L'ancien compteur (par envoi, sans compte) n'est pas recopié
        $this->connection->executeStatement("INSERT INTO drive_department_rayon (retailer, department_slug, rayon_id, chosen_count) VALUES ('leclerc', ?, 4999, 50)", [$bakery]);

        // When
        $this->runMigration(new Version20261027180100($this->connection, new NullLogger()));

        // Then : un vote par compte et par couple (rayon de Repas, rayon de l'enseigne), rien d'autre
        $this->assertEqualsCanonicalizing(
            [[$alice->getId(), $bakery, 4001], [$alice->getId(), $misc, 4002], [$bob->getId(), $bakery, 4001]],
            $this->votes(),
        );
        $this->assertSame(50, (int) $this->connection->fetchOne('SELECT chosen_count FROM drive_department_rayon WHERE rayon_id = 4999'));
    }

    public function testReplayingTheStartingVotesChangesNothing(): void
    {
        // Given
        $alice = new UserBuilder()->build();
        $persister = new DataPersister(static::getContainer());
        $flour = new IngredientBuilder()->isFlour()->withName('Farine d\'Alice')->withSlug('farine-alice')->withCreator($alice)->build();
        $persister->saveIngredient($flour);
        $product = new ProductBuilder()->withRetailerProductId('1')->withRayon(4001)->build();
        static::getContainer()->get(ProductRepository::class)->saveAll([$product]);
        static::getContainer()->get(ChoiceRepository::class)->saveAll([
            new ChoiceBuilder()->withUserId($alice->getId())->withIngredientSlug('farine-alice')->withAccepted([$product->getId()])->build(),
        ]);
        $this->runMigration(new Version20261027180100($this->connection, new NullLogger()));
        $before = $this->votes();

        // When
        $this->runMigration(new Version20261027180100($this->connection, new NullLogger()));

        // Then
        $this->assertSame([[$alice->getId(), $this->departmentOf($flour), 4001]], $before);
        $this->assertSame($before, $this->votes());
    }

    public function testAnAccountKeepsAtMostTheMaximumNumberOfPairsAndTheOthersAreUntouched(): void
    {
        // Given : Alice a un produit accepté dans chacun de 600 rayons de l'enseigne différents pour un même ingrédient (lignes écrites en SQL), Bob un seul
        $alice = new UserBuilder()->build();
        $bob = new UserBuilder()->build();
        $persister = new DataPersister(static::getContainer());
        $persister->saveIngredient(new IngredientBuilder()->isFlour()->withName('Farine d\'Alice')->withSlug('farine-alice')->withCreator($alice)->build());
        $persister->saveIngredient(new IngredientBuilder()->isFlour()->withName('Farine de Bob')->withSlug('farine-bob')->withCreator($bob)->build());
        $product = new ProductBuilder()->withRetailerProductId('1')->withRayon(4001)->build();
        static::getContainer()->get(ProductRepository::class)->saveAll([$product]);
        $aliceChoice = new ChoiceBuilder()->withUserId($alice->getId())->withIngredientSlug('farine-alice')->build();
        static::getContainer()->get(ChoiceRepository::class)->saveAll([
            $aliceChoice,
            new ChoiceBuilder()->withUserId($bob->getId())->withIngredientSlug('farine-bob')->withAccepted([$product->getId()])->build(),
        ]);
        $this->connection->executeStatement(
            "INSERT INTO drive_product (id, retailer, store_id, retailer_product_id, label, last_seen_at, rayon_id) SELECT 'bulk-' || g, 'leclerc', '123456', 'bulk' || g, 'Produit ' || g, now(), 6000 + g FROM generate_series(1, 600) AS g",
        );
        $this->connection->executeStatement(
            "INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank) SELECT ?, 'bulk-' || g, 'ACCEPTED', NULL FROM generate_series(1, 600) AS g",
            [$aliceChoice->getId()],
        );

        // When
        $this->runMigration(new Version20261027180100($this->connection, new NullLogger()));

        // Then
        $this->assertSame(RayonVote::MAX_PER_USER_AND_RETAILER, (int) $this->connection->fetchOne('SELECT count(*) FROM drive_rayon_vote WHERE user_id = ?', [$alice->getId()]));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM drive_rayon_vote WHERE user_id = ?', [$bob->getId()]));
    }

    public function testRollingBackTheStartingVotesRemovesNothing(): void
    {
        // Given
        $this->connection->executeStatement("INSERT INTO drive_rayon_vote (user_id, retailer, department_slug, rayon_id) VALUES ('user-1', 'leclerc', 'boulangerie', 4001)");
        $migration = new Version20261027180100($this->connection, new NullLogger());

        // When
        $migration->down(new Schema());

        // Then : l'instruction neutre, rien d'autre
        $this->assertSame(['SELECT 1'], array_map(static fn($query): string => $query->getStatement(), $migration->getSql()));
        $this->assertSame([['user-1', 'boulangerie', 4001]], $this->votes());
    }

    private function runMigration(AbstractMigration $migration): void
    {
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    private function departmentOf(Ingredient $ingredient): string
    {
        return $ingredient->getDepartment()->getSlug();
    }

    /**
     * @return list<array{string, string, int}> compte, rayon de Repas, rayon de l'enseigne
     */
    private function votes(): array
    {
        return array_map(
            static fn(array $row): array => [$row[0], $row[1], (int) $row[2]],
            $this->connection->fetchAllNumeric('SELECT user_id, department_slug, rayon_id FROM drive_rayon_vote ORDER BY user_id, department_slug, rayon_id'),
        );
    }

    /**
     * @return array{columns: list<array<string, mixed>>, primaryKey: list<string>, indexes: list<string>}
     */
    private function tableSnapshot(): array
    {
        return [
            'columns' => $this->connection->fetchAllAssociative(
                "SELECT column_name, data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = 'drive_rayon_vote' ORDER BY ordinal_position",
            ),
            'primaryKey' => $this->connection->fetchFirstColumn(
                "SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = 'drive_rayon_vote'::regclass AND i.indisprimary ORDER BY array_position(i.indkey::int[], a.attnum::int)",
            ),
            'indexes' => $this->connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE tablename = 'drive_rayon_vote' ORDER BY indexname"),
        ];
    }
}
