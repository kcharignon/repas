<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027100000;
use DoctrineMigrations\Version20261027150000;
use Psr\Log\NullLogger;
use Repas\Repas\Domain\Model\ReferenceUniversalConversions;
use Repas\Repas\Infrastructure\DataFixture\ConversionFixture;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261027100000.php';
require_once dirname(__DIR__, 3) . '/migrations/Version20261027150000.php';

/**
 * Le correctif des conversions universelles (`Version20261027150000`), sur la VRAIE base : en production, « centilitre > millilitre » manquait
 * encore après la première migration, son identifiant fixe étant pris par une conversion détournée (l'ancien `PUT` d'un ingrédient avait rattaché
 * la ligne universelle à l'ingrédient qu'il enregistrait). La ligne neuve prend alors un identifiant dérivé et stable ; rien d'existant n'est touché.
 */
class UniversalConversionsCorrectionMigrationTest extends DatabaseTestCase
{
    /** L'identifiant fixe de « centilitre > millilitre x 10 », celui que la production avait laissé à une conversion détournée. */
    private const string CENTILITRE_ID = '8f31ad66-feaf-4c35-a17b-c755d8c1c889';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testTheThirteenLinesAreTheSameInTheDomainTheTwoMigrationsAndTheReferenceFile(): void
    {
        // Given : le fichier que charge la fixture du groupe `prod`
        $rows = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/src/Repas/Infrastructure/DataFixture/Data/' . ConversionFixture::FILE_NAME), true, flags: JSON_THROW_ON_ERROR);
        $universal = array_values(array_filter($rows, static fn(array $row): bool => $row['ingredient'] === null));

        // Then : les mêmes 13 lignes, dans le même ordre, avec les mêmes identifiants
        $fromFile = array_map(static fn(array $row): array => [$row['id'], $row['start_unit'], $row['end_unit'], $row['coefficient']], $universal);
        $this->assertCount(13, constant(ReferenceUniversalConversions::class . '::LINES'));
        $this->assertSame($fromFile, constant(ReferenceUniversalConversions::class . '::LINES'));
        // Les constantes sont lues à l'exécution (`constant()`) : le même contenu écrit à deux endroits est exactement ce que ce test garde
        $this->assertSame(constant(Version20261027100000::class . '::UNIVERSAL_CONVERSIONS'), constant(Version20261027150000::class . '::UNIVERSAL_CONVERSIONS'));
        $this->assertSame(constant(ReferenceUniversalConversions::class . '::LINES'), constant(Version20261027150000::class . '::UNIVERSAL_CONVERSIONS'));
    }

    public function testAnOccupiedFixedIdentifierGivesTheNewUniversalLineADerivedIdentifier(): void
    {
        // Given : le cas de la production. L'identifiant fixe de « centilitre > millilitre » désigne une conversion de la farine (détournée), et
        // les douze autres universelles sont là
        $this->insertUnits($this->allUnits());
        $this->insertReferenceLinesExcept(['centilitre > millilitre']);
        $this->insertConversion(self::CENTILITRE_ID, 'tasse-a-cafe', 'gramme', 120, 'farine');

        // When
        $this->runMigration();

        // Then : une ligne UNIVERSELLE de plus, sous l'identifiant dérivé, avec la paire et le coefficient de la référence
        $derived = $this->derivedIdentifier(self::CENTILITRE_ID);
        $this->assertSame(
            [[$derived, 'centilitre', 'millilitre', 10.0, null]],
            $this->rows("SELECT id, start_unit, end_unit, coefficient::float AS coefficient, ingredient FROM conversion WHERE ingredient IS NULL AND start_unit = 'centilitre' AND end_unit = 'millilitre'"),
        );
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));

        // And : la conversion détournée n'a pas bougé, elle reste propre à la farine
        $this->assertSame(
            [[self::CENTILITRE_ID, 'tasse-a-cafe', 'gramme', 120.0, 'farine']],
            $this->rows('SELECT id, start_unit, end_unit, coefficient::float AS coefficient, ingredient FROM conversion WHERE id = ?', [self::CENTILITRE_ID]),
        );
        $this->assertSame(14, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
    }

    public function testReplayingItChangesNothingEvenWhenTheFixedIdentifierIsStillTaken(): void
    {
        // Given : la même situation, déjà corrigée une fois
        $this->insertUnits($this->allUnits());
        $this->insertReferenceLinesExcept(['centilitre > millilitre']);
        $this->insertConversion(self::CENTILITRE_ID, 'tasse-a-cafe', 'gramme', 120, 'farine');
        $this->runMigration();
        $before = $this->rows('SELECT id, start_unit, end_unit, coefficient::float AS coefficient, ingredient FROM conversion ORDER BY id');

        // When : rejouée (la paire est reliée, la garde l'arrête avant tout identifiant)
        $this->runMigration();

        // Then
        $this->assertSame($before, $this->rows('SELECT id, start_unit, end_unit, coefficient::float AS coefficient, ingredient FROM conversion ORDER BY id'));
    }

    public function testItCreatesTheThirteenLinesUnderTheirFixedIdentifiersOnAnEmptyTable(): void
    {
        // Given : toutes les unités, aucune conversion
        $this->insertUnits($this->allUnits());

        // When
        $this->runMigration();

        // Then : les 13 lignes, universelles, sous les identifiants du fichier (rien n'est dérivé quand ils sont libres)
        $ids = array_map(static fn(array $row): string => $row[0], $this->rows('SELECT id FROM conversion WHERE ingredient IS NULL'));
        $expected = ReferenceUniversalConversions::ids();
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
    }

    public function testAPairAlreadyUniversalIsNeverDoubledWhateverItsIdentifierDirectionOrCoefficient(): void
    {
        // Given : « kilo > gramme x 1000 » ajoutée à la main sous un autre identifiant, « millilitre > litre » (l'autre sens de litre > millilitre) et
        // « centilitre > millilitre x 9 » (un autre coefficient), toutes universelles
        $this->insertUnits($this->allUnits());
        $this->insertConversion('9c1f6a52-0b7e-4d3a-8e55-3f0a1d7b2c44', 'kilo', 'gramme', 1000, null);
        $this->insertConversion('mine-2', 'millilitre', 'litre', 0.001, null);
        $this->insertConversion('mine-3', 'centilitre', 'millilitre', 9, null);
        // Et l'identifiant fixe de « centilitre > millilitre » est pris : la paire étant déjà reliée, rien n'est créé pour elle quand même
        $this->insertConversion(self::CENTILITRE_ID, 'tasse-a-cafe', 'gramme', 120, 'farine');

        // When
        $this->runMigration();

        // Then : ces lignes sont intactes, leurs trois paires ne sont pas doublées, les dix autres sont créées
        $this->assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM conversion WHERE ingredient IS NULL AND ((start_unit = 'kilo' AND end_unit = 'gramme') OR (start_unit = 'gramme' AND end_unit = 'kilo'))"));
        $this->assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM conversion WHERE ingredient IS NULL AND ((start_unit = 'litre' AND end_unit = 'millilitre') OR (start_unit = 'millilitre' AND end_unit = 'litre'))"));
        $this->assertSame([9.0], array_map(static fn(array $row): float => $row[0], $this->rows("SELECT coefficient::float FROM conversion WHERE ingredient IS NULL AND start_unit = 'centilitre' AND end_unit = 'millilitre'")));
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion WHERE id = ?', [$this->derivedIdentifier(self::CENTILITRE_ID)]));
    }

    public function testAConversionOfAnIngredientDoesNotStandInForTheUniversalOne(): void
    {
        // Given : la farine a sa propre ligne kilo > gramme, rien d'universel
        $this->insertUnits($this->allUnits());
        $this->insertConversion('farine-kilo', 'kilo', 'gramme', 950, 'farine');

        // When
        $this->runMigration();

        // Then : la ligne universelle est créée, celle de la farine reste telle quelle
        $this->assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM conversion WHERE ingredient IS NULL AND start_unit = 'kilo' AND end_unit = 'gramme'"));
        $this->assertSame([[950.0, 'farine']], $this->rows("SELECT coefficient::float, ingredient FROM conversion WHERE id = 'farine-kilo'"));
    }

    public function testALineIsOnlyCreatedWhenBothUnitsExist(): void
    {
        // Given : le paquet n'existe pas
        $this->insertUnits(array_values(array_diff($this->allUnits(), ['paquet'])));

        // When
        $this->runMigration();

        // Then : « sachet > paquet » et « boîte > paquet » manquent, les onze autres sont là
        $this->assertSame(11, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IS NULL'));
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM conversion WHERE end_unit = 'paquet'"));

        // And : sans aucune unité, rien du tout
        $this->connection->executeStatement('DELETE FROM conversion');
        $this->connection->executeStatement('DELETE FROM unit');
        $this->runMigration();
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
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
        $this->assertSame(13, (int) $this->connection->fetchOne('SELECT count(*) FROM conversion'));
    }

    private function migration(): Version20261027150000
    {
        return new Version20261027150000($this->connection, new NullLogger());
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
     * L'identifiant dérivé, calculé ICI à part du code de production : `md5(identifiant fixe || ':universelle')` écrit en UUID.
     */
    private function derivedIdentifier(string $fixed): string
    {
        $hash = md5($fixed . ':universelle');

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
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

    /**
     * @param list<string> $except les paires « début > fin » à ne pas créer
     */
    private function insertReferenceLinesExcept(array $except): void
    {
        foreach (ReferenceUniversalConversions::LINES as [$id, $start, $end, $coefficient]) {
            if (!in_array("$start > $end", $except, true)) {
                $this->insertConversion($id, $start, $end, $coefficient, null);
            }
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
     * @param list<mixed> $parameters
     * @return list<list<mixed>>
     */
    private function rows(string $sql, array $parameters = []): array
    {
        return $this->connection->executeQuery($sql, $parameters)->fetchAllNumeric();
    }
}
