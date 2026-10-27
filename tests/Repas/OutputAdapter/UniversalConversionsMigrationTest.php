<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027100000;
use Psr\Log\NullLogger;
use Repas\Repas\Infrastructure\DataFixture\ConversionFixture;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261027100000.php';

/**
 * Les conversions universelles (celles de TOUS les ingrédients : `conversion.ingredient` vide), sur la VRAIE base : la migration qui les
 * remet quand elles manquent sans jamais écraser ni doubler une ligne existante, et les données de référence qui les portent.
 */
class UniversalConversionsMigrationTest extends DatabaseTestCase
{
    /** Les 13 conversions universelles attendues, écrites ici à la main : les retirer des données de référence doit faire du bruit. */
    private const array EXPECTED = [
        'litre > centilitre x 100',
        'litre > millilitre x 1000',
        'centilitre > millilitre x 10',
        'bol > centilitre x 35',
        'verre > centilitre x 25',
        'tasse-a-cafe > centilitre x 10',
        'cuillere-a-cafe > millilitre x 5',
        'cuillere-a-soupe > millilitre x 15',
        'kilo > gramme x 1000',
        'sachet > paquet x 1',
        'boite > paquet x 1',
        'conserve > boite x 1',
        'pot > boite x 1',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheReferenceDataHoldTheThirteenUniversalConversionsAndLoadThemInProduction(): void
    {
        // Given : le fichier que charge la fixture du groupe `prod`
        $rows = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/src/Repas/Infrastructure/DataFixture/Data/' . ConversionFixture::FILE_NAME), true, flags: JSON_THROW_ON_ERROR);

        // When
        $universal = array_values(array_filter($rows, static fn(array $row): bool => $row['ingredient'] === null));

        // Then : les mêmes 13, avec les identifiants que la migration reprend
        $this->assertSame(
            self::EXPECTED,
            array_map(static fn(array $row): string => sprintf('%s > %s x %s', $row['start_unit'], $row['end_unit'], $row['coefficient']), $universal),
        );
        $this->assertSame(
            array_map(static fn(array $row): array => [$row['id'], $row['start_unit'], $row['end_unit'], $row['coefficient']], $universal),
            Version20261027100000::UNIVERSAL_CONVERSIONS,
        );
        $this->assertContains('prod', ConversionFixture::getGroups());
    }

    public function testItCreatesTheUniversalConversionsOnAnEmptyTableAndReplayingItChangesNothing(): void
    {
        // Given : toutes les unités, aucune conversion
        $this->insertUnits($this->allUnits());

        // When
        $this->runMigration();

        // Then : les 13 lignes, universelles, avec les identifiants du fichier
        $this->assertSame(self::EXPECTED, $this->universalConversions());
        $this->assertSame('2112ac45-a451-4952-8f9c-ccb9d76c3551', $this->connection->fetchOne("SELECT id FROM conversion WHERE start_unit = 'kilo' AND end_unit = 'gramme'"));

        // When : rejouée
        $this->runMigration();

        // Then : rien de plus
        $this->assertSame(self::EXPECTED, $this->universalConversions());
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
    }

    public function testAnExistingLineIsNeverOverwrittenNorDoubledWhateverItsCoefficientOrDirection(): void
    {
        // Given : « 1 kilo = 900 grammes » (autre coefficient) et « millilitre > litre » (l'autre sens de litre > millilitre), universelles
        $this->insertUnits($this->allUnits());
        $this->insertConversion('mine-1', 'kilo', 'gramme', 900, null);
        $this->insertConversion('mine-2', 'millilitre', 'litre', 0.001, null);

        // When
        $this->runMigration();

        // Then : ces deux lignes sont intactes, leurs paires ne sont pas doublées, les onze autres sont créées
        $this->assertSame(['gramme' => 900.0], $this->coefficientsFrom('kilo'));
        $this->assertSame(['litre' => 0.001], $this->coefficientsFrom('millilitre'));
        $this->assertSame([], $this->coefficientsFrom('litre', 'millilitre'));
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM conversion WHERE id IN ('2112ac45-a451-4952-8f9c-ccb9d76c3551', '58db18f7-3c62-421c-9736-0327528de05d')"));
    }

    public function testALineAlreadyThereWithAnotherIdentifierAndTheSameCoefficientIsNotDoubled(): void
    {
        // Given : le propriétaire a ajouté « kilo > gramme x 1000 » à la main (écran d'administration), sous un autre identifiant que celui du fichier
        $this->insertUnits($this->allUnits());
        $this->insertConversion('9c1f6a52-0b7e-4d3a-8e55-3f0a1d7b2c44', 'kilo', 'gramme', 1000, null);

        // When : la garde se fait sur la paire d'unités, jamais sur l'identifiant
        $this->runMigration();

        // Then : une seule ligne pour la paire, la sienne ; les douze autres sont créées
        $this->assertSame(
            ['9c1f6a52-0b7e-4d3a-8e55-3f0a1d7b2c44'],
            $this->connection->fetchFirstColumn("SELECT id FROM conversion WHERE ingredient IS NULL AND ((start_unit = 'kilo' AND end_unit = 'gramme') OR (start_unit = 'gramme' AND end_unit = 'kilo'))"),
        );
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));

        // And : rejouée, toujours rien de plus
        $this->runMigration();
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
    }

    public function testAConversionOfAnIngredientDoesNotStandInForTheUniversalOne(): void
    {
        // Given : la farine a sa propre ligne kilo > gramme, rien d'universel
        $this->insertUnits($this->allUnits());
        $this->insertConversion('farine-kilo', 'kilo', 'gramme', 950, 'farine');

        // When
        $this->runMigration();

        // Then : la ligne universelle est créée, celle de la farine reste telle quelle
        $this->assertContains('kilo > gramme x 1000', $this->universalConversions());
        $this->assertSame(950.0, (float) $this->connection->fetchOne("SELECT coefficient FROM conversion WHERE id = 'farine-kilo'"));
        $this->assertSame('farine', $this->connection->fetchOne("SELECT ingredient FROM conversion WHERE id = 'farine-kilo'"));
    }

    public function testALineIsOnlyCreatedWhenBothUnitsExist(): void
    {
        // Given : le paquet n'existe pas
        $this->insertUnits(array_values(array_diff($this->allUnits(), ['paquet'])));

        // When
        $this->runMigration();

        // Then : « sachet > paquet » et « boîte > paquet » manquent, les onze autres sont là
        $universal = $this->universalConversions();
        $this->assertNotContains('sachet > paquet x 1', $universal);
        $this->assertNotContains('boite > paquet x 1', $universal);
        $this->assertCount(11, $universal);

        // And : sans aucune unité, rien du tout
        $this->connection->executeStatement('DELETE FROM conversion');
        $this->connection->executeStatement('DELETE FROM unit');
        $this->runMigration();
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
    }

    public function testAnIdentifierAlreadyTakenByAnotherLineIsLeftAloneWithoutFailing(): void
    {
        // Given : l'identifiant de « kilo > gramme » désigne déjà une conversion de la farine entre d'autres unités
        $this->insertUnits($this->allUnits());
        $this->insertConversion('2112ac45-a451-4952-8f9c-ccb9d76c3551', 'tasse-a-cafe', 'gramme', 120, 'farine');

        // When
        $this->runMigration();

        // Then : aucune erreur, la ligne de la farine est intacte, les douze autres sont créées
        $this->assertSame('farine', $this->connection->fetchOne("SELECT ingredient FROM conversion WHERE id = '2112ac45-a451-4952-8f9c-ccb9d76c3551'"));
        $this->assertSame(12, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));
    }

    public function testRollingBackRemovesNothing(): void
    {
        // Given
        $this->insertUnits($this->allUnits());
        $this->runMigration();
        $migration = $this->migration();

        // When
        $migration->down(new Schema());

        // Then : l'instruction neutre, rien d'autre
        $this->assertSame(['SELECT 1'], array_map(static fn($query): string => $query->getStatement(), $migration->getSql()));
    }

    private function migration(): Version20261027100000
    {
        return new Version20261027100000($this->connection, new NullLogger());
    }

    private function runMigration(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    /**
     * @return list<string>
     */
    private function allUnits(): array
    {
        return ['gramme', 'kilo', 'millilitre', 'centilitre', 'litre', 'bol', 'verre', 'tasse-a-cafe', 'cuillere-a-cafe', 'cuillere-a-soupe', 'sachet', 'paquet', 'boite', 'conserve', 'pot'];
    }

    /**
     * @param list<string> $slugs
     */
    private function insertUnits(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->connection->executeStatement('INSERT INTO unit (slug, name, symbol, dimension, base_factor) VALUES (?, ?, ?, NULL, NULL)', [$slug, $slug, $slug]);
        }
    }

    private function insertConversion(string $id, string $start, string $end, float $coefficient, ?string $ingredient): void
    {
        $this->connection->executeStatement(
            'INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient) VALUES (?, ?, ?, ?, ?)',
            [$id, $start, $end, $coefficient, $ingredient],
        );
    }

    /**
     * @return list<string> « début > fin x coefficient », dans l'ordre du fichier de référence
     */
    private function universalConversions(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, start_unit, end_unit, coefficient FROM conversion WHERE ingredient IS NULL');
        $order = array_flip(array_column(Version20261027100000::UNIVERSAL_CONVERSIONS, 0));
        usort($rows, static fn(array $a, array $b): int => ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX));

        return array_map(static fn(array $row): string => sprintf('%s > %s x %s', $row['start_unit'], $row['end_unit'], (float) $row['coefficient']), $rows);
    }

    /**
     * @return array<string, float> l'unité d'arrivée => le coefficient des conversions universelles qui partent de `$start`
     */
    private function coefficientsFrom(string $start, ?string $end = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT end_unit, coefficient FROM conversion WHERE ingredient IS NULL AND start_unit = ?' . ($end === null ? '' : ' AND end_unit = ?'),
            $end === null ? [$start] : [$start, $end],
        );

        return array_column(array_map(static fn(array $row): array => [$row['end_unit'], (float) $row['coefficient']], $rows), 1, 0);
    }
}
