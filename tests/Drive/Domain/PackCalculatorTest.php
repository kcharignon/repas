<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\LineQuantity;
use Repas\Drive\Domain\Model\PackResult;
use Repas\Drive\Domain\Model\PackStatus;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Service\PackCalculator;
use Repas\Shared\Domain\Model\Dimension;

class PackCalculatorTest extends TestCase
{
    private const string COMPUTED = 'COMPUTED';
    private const string TO_CHECK = 'TO_CHECK';

    /**
     * @return iterable<string, array{LineQuantity, ?ProductContent, ?int, string}>
     */
    public static function theTableOfTheTicket(): iterable
    {
        yield '2 paquets contre 500 g' => [self::package(2), self::content(500, ContentUnit::G), 2, self::COMPUTED];
        yield '300 g contre 1 kg' => [self::weight(300, 1), self::content(1, ContentUnit::KG), 1, self::COMPUTED];
        yield '1,2 kg contre 500 g' => [self::weight(1.2, 1000), self::content(500, ContentUnit::G), 3, self::COMPUTED];
        yield '750 g contre 1000 g' => [self::weight(750, 1), self::content(1000, ContentUnit::G), 1, self::COMPUTED];
        yield '1000 g contre 1000 g (pas de 2 par flottant)' => [self::weight(1000, 1), self::content(1000, ContentUnit::G), 1, self::COMPUTED];
        yield '30 cl contre 1 l' => [self::volume(30, 10), self::content(1, ContentUnit::L), 1, self::COMPUTED];
        yield '6 unités contre 6 pièces' => [self::units(6), self::content(6, ContentUnit::PIECE), 1, self::COMPUTED];
        yield '8 unités contre 6 pièces' => [self::units(8), self::content(6, ContentUnit::PIECE), 2, self::COMPUTED];
        yield '3 unités sans contenu' => [self::units(3), null, 3, self::TO_CHECK];
        yield '300 g contre 6 pièces' => [self::weight(300, 1), self::content(6, ContentUnit::PIECE), null, self::TO_CHECK];
        yield '300 g sans contenu' => [self::weight(300, 1), null, null, self::TO_CHECK];
        yield 'quantité nulle' => [self::weight(0, 1), self::content(1, ContentUnit::KG), null, self::TO_CHECK];
        yield 'quantité négative' => [self::weight(-2, 1), self::content(1, ContentUnit::KG), null, self::TO_CHECK];
        yield '500 paquets' => [self::package(500), self::content(500, ContentUnit::G), 99, self::TO_CHECK];
    }

    #[DataProvider('theTableOfTheTicket')]
    public function testTheTableOfTheTicket(LineQuantity $line, ?ProductContent $content, ?int $packs, string $status): void
    {
        // When
        $result = new PackCalculator()->packsFor($line, $content);

        // Then
        $this->assertSame([$packs, $status], [$result->packs, $result->status->value]);
    }

    /**
     * @return iterable<string, array{LineQuantity, ?ProductContent, ?int, string}>
     */
    public static function rule1Package(): iterable
    {
        yield 'sans contenu' => [self::package(3), null, 3, self::COMPUTED];
        yield 'le contenu est ignoré' => [self::package(3), self::content(6, ContentUnit::PIECE), 3, self::COMPUTED];
        yield 'contenu inutilisable ignoré aussi' => [self::package(3), self::content(0, ContentUnit::G), 3, self::COMPUTED];
        yield 'arrondi supérieur' => [self::package(1.5), null, 2, self::COMPUTED];
        yield 'un demi' => [self::package(0.5), null, 1, self::COMPUTED];
        yield 'entier à la tolérance près' => [self::package(2.0000000001), null, 2, self::COMPUTED];
        yield 'au-delà de la tolérance' => [self::package(2.000001), null, 3, self::COMPUTED];
        yield 'minimum 1 pour une quantité minuscule' => [self::package(1e-12), null, 1, self::COMPUTED];
        yield 'le plafond lui-même' => [self::package(99), null, 99, self::COMPUTED];
        yield 'le plafond à la tolérance près' => [self::package(99.0000000001), null, 99, self::COMPUTED];
        yield 'un de plus que le plafond' => [self::package(100), null, 99, self::TO_CHECK];
        yield 'arrondi qui dépasse le plafond' => [self::package(99.5), null, 99, self::TO_CHECK];
        yield 'sans facteur de base' => [new LineQuantity(2, Dimension::PACKAGE, null), null, 2, self::COMPUTED];
    }

    #[DataProvider('rule1Package')]
    public function testRule1APackageLineCountsProductsAndIgnoresTheContent(LineQuantity $line, ?ProductContent $content, ?int $packs, string $status): void
    {
        $this->assertResult($packs, $status, new PackCalculator()->packsFor($line, $content));
    }

    /**
     * @return iterable<string, array{LineQuantity, ?ProductContent, ?int, string}>
     */
    public static function rule2Count(): iterable
    {
        yield 'pile le contenu' => [self::units(6), self::content(6, ContentUnit::PIECE), 1, self::COMPUTED];
        yield 'un de plus que le contenu' => [self::units(7), self::content(6, ContentUnit::PIECE), 2, self::COMPUTED];
        yield 'produit à une pièce' => [self::units(12), self::content(1, ContentUnit::PIECE), 12, self::COMPUTED];
        yield 'quantité décimale' => [self::units(2.5), self::content(1, ContentUnit::PIECE), 3, self::COMPUTED];
        yield 'moins que le contenu' => [self::units(2), self::content(6, ContentUnit::PIECE), 1, self::COMPUTED];
        yield 'sans contenu : une unité, un produit' => [self::units(3), null, 3, self::TO_CHECK];
        yield 'sans contenu, quantité décimale' => [self::units(2.5), null, 3, self::TO_CHECK];
        yield 'contenu en poids' => [self::units(3), self::content(500, ContentUnit::G), 3, self::TO_CHECK];
        yield 'contenu en volume' => [self::units(3), self::content(1, ContentUnit::L), 3, self::TO_CHECK];
        yield 'contenu inutilisable : comme un contenu inconnu' => [self::units(3), self::content(0, ContentUnit::PIECE), 3, self::TO_CHECK];
        yield 'contenu négatif : comme un contenu inconnu' => [self::units(3), self::content(-6, ContentUnit::PIECE), 3, self::TO_CHECK];
        yield 'contenu qui n\'est pas un nombre' => [self::units(3), self::content(NAN, ContentUnit::PIECE), 3, self::TO_CHECK];
        yield 'contenu infini' => [self::units(3), self::content(INF, ContentUnit::PIECE), 3, self::TO_CHECK];
        yield 'plafond sans contenu' => [self::units(500), null, 99, self::TO_CHECK];
        yield 'plafond avec contenu' => [self::units(500), self::content(1, ContentUnit::PIECE), 99, self::TO_CHECK];
        yield 'pile le plafond avec contenu' => [self::units(99), self::content(1, ContentUnit::PIECE), 99, self::COMPUTED];
        yield 'le facteur de base de la ligne n\'entre pas en compte' => [new LineQuantity(6, Dimension::COUNT, null), self::content(6, ContentUnit::PIECE), 1, self::COMPUTED];
    }

    #[DataProvider('rule2Count')]
    public function testRule2ACountLineDividesByThePiecesOfTheProduct(LineQuantity $line, ?ProductContent $content, ?int $packs, string $status): void
    {
        $this->assertResult($packs, $status, new PackCalculator()->packsFor($line, $content));
    }

    /**
     * @return iterable<string, array{LineQuantity, ?ProductContent, ?int, string}>
     */
    public static function rule3WeightAndVolume(): iterable
    {
        yield 'kilos contre grammes' => [self::weight(2, 1000), self::content(500, ContentUnit::G), 4, self::COMPUTED];
        yield 'grammes contre kilos' => [self::weight(600, 1), self::content(0.5, ContentUnit::KG), 2, self::COMPUTED];
        yield 'grammes contre demi-kilo, pile' => [self::weight(500, 1), self::content(0.5, ContentUnit::KG), 1, self::COMPUTED];
        yield 'un gramme de plus que le contenu' => [self::weight(1001, 1), self::content(1, ContentUnit::KG), 2, self::COMPUTED];
        yield 'litres contre centilitres' => [self::volume(1.5, 1000), self::content(33, ContentUnit::CL), 5, self::COMPUTED];
        yield 'millilitres contre litre' => [self::volume(250, 1), self::content(1, ContentUnit::L), 1, self::COMPUTED];
        yield 'millilitres contre centilitres' => [self::volume(1000, 1), self::content(75, ContentUnit::CL), 2, self::COMPUTED];
        yield 'centilitres contre millilitres, pile' => [self::volume(50, 10), self::content(500, ContentUnit::ML), 1, self::COMPUTED];
        yield 'litre contre litre' => [self::volume(1, 1000), self::content(1, ContentUnit::L), 1, self::COMPUTED];
        yield 'quantité minuscule : au moins 1' => [self::weight(0.000001, 1), self::content(1, ContentUnit::KG), 1, self::COMPUTED];
        yield 'plafond' => [self::weight(500, 1000), self::content(1, ContentUnit::G), 99, self::TO_CHECK];
        yield 'pile le plafond' => [self::weight(99, 1), self::content(1, ContentUnit::G), 99, self::COMPUTED];
        yield 'quantité énorme' => [self::weight(PHP_FLOAT_MAX, 1000), self::content(1, ContentUnit::G), 99, self::TO_CHECK];
        yield 'contenu minuscule' => [self::weight(1, 1), self::content(1e-300, ContentUnit::G), 99, self::TO_CHECK];
    }

    #[DataProvider('rule3WeightAndVolume')]
    public function testRule3AWeightOrVolumeLineDividesByTheContentOfTheSameDimension(LineQuantity $line, ?ProductContent $content, ?int $packs, string $status): void
    {
        $this->assertResult($packs, $status, new PackCalculator()->packsFor($line, $content));
    }

    /**
     * @return iterable<string, array{LineQuantity, ?ProductContent}>
     */
    public static function rule4EverythingElse(): iterable
    {
        yield 'poids contre pièces' => [self::weight(300, 1), self::content(6, ContentUnit::PIECE)];
        yield 'poids contre volume' => [self::weight(300, 1), self::content(1, ContentUnit::L)];
        yield 'volume contre poids' => [self::volume(300, 1), self::content(1, ContentUnit::KG)];
        yield 'volume contre pièces' => [self::volume(300, 1), self::content(6, ContentUnit::PIECE)];
        yield 'poids sans contenu' => [self::weight(300, 1), null];
        yield 'volume sans contenu' => [self::volume(300, 1), null];
        yield 'poids avec un contenu nul' => [self::weight(300, 1), self::content(0, ContentUnit::G)];
        yield 'poids avec un contenu négatif' => [self::weight(300, 1), self::content(-1, ContentUnit::KG)];
        yield 'poids avec un contenu qui n\'est pas un nombre' => [self::weight(300, 1), self::content(NAN, ContentUnit::G)];
        yield 'poids avec un contenu infini' => [self::weight(300, 1), self::content(INF, ContentUnit::G)];
        yield 'poids sans facteur de base' => [new LineQuantity(300, Dimension::WEIGHT, null), self::content(1, ContentUnit::KG)];
        yield 'poids avec un facteur de base nul' => [new LineQuantity(300, Dimension::WEIGHT, 0.0), self::content(1, ContentUnit::KG)];
        yield 'poids avec un facteur de base négatif' => [new LineQuantity(300, Dimension::WEIGHT, -1.0), self::content(1, ContentUnit::KG)];
        yield 'poids avec un facteur de base infini' => [new LineQuantity(300, Dimension::WEIGHT, INF), self::content(1, ContentUnit::KG)];
        yield 'unité sans dimension' => [new LineQuantity(2, null, null), self::content(500, ContentUnit::G)];
        yield 'unité sans dimension, sans contenu' => [new LineQuantity(2, null, null), null];
        yield 'unité sans dimension mais avec un facteur' => [new LineQuantity(2, null, 1.0), self::content(6, ContentUnit::PIECE)];
    }

    #[DataProvider('rule4EverythingElse')]
    public function testRule4AnyOtherCaseHasNoPackCountAndMustBeChecked(LineQuantity $line, ?ProductContent $content): void
    {
        $this->assertResult(null, self::TO_CHECK, new PackCalculator()->packsFor($line, $content));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function unusableQuantities(): iterable
    {
        yield 'zéro' => [0.0];
        yield 'zéro négatif' => [-0.0];
        yield 'négative' => [-1.0];
        yield 'très négative' => [-1e12];
        yield 'infinie' => [INF];
        yield 'infiniment négative' => [-INF];
        yield 'pas un nombre' => [NAN];
    }

    #[DataProvider('unusableQuantities')]
    public function testRule5AnUnusableQuantityHasNoPackCountWhateverTheLineAndTheContent(float $quantity): void
    {
        // Given
        $calculator = new PackCalculator();
        $content = self::content(1, ContentUnit::PIECE);

        // Then
        foreach ([Dimension::PACKAGE, Dimension::COUNT, Dimension::WEIGHT, Dimension::VOLUME, null] as $dimension) {
            foreach ([null, $content] as $productContent) {
                $this->assertResult(null, self::TO_CHECK, $calculator->packsFor(new LineQuantity($quantity, $dimension, 1.0), $productContent));
            }
        }
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function toleranceAroundAnInteger(): iterable
    {
        yield 'pile' => [1.0, 1];
        yield 'juste au-dessus, dans la tolérance' => [1 + 5e-10, 1];
        yield 'juste au-dessous, dans la tolérance' => [2 - 5e-10, 2];
        yield 'juste au-dessus, hors tolérance' => [1 + 2e-9, 2];
        yield 'juste au-dessous, hors tolérance (arrondi supérieur)' => [2 - 2e-9, 2];
        yield 'bien au-dessus' => [1.001, 2];
        yield 'un quart' => [0.25, 1];
    }

    #[DataProvider('toleranceAroundAnInteger')]
    public function testRule5TheCeilingToleratesAnErrorOfOneNanoUnit(float $kilos, int $expected): void
    {
        // When : le quotient est la quantité en kilos contre un produit d'un kilo
        $result = new PackCalculator()->packsFor(self::weight($kilos, 1000), self::content(1, ContentUnit::KG));

        // Then
        $this->assertResult($expected, self::COMPUTED, $result);
    }

    public function testFloatingPointNoiseDoesNotAddAProduct(): void
    {
        // Given : 0,1 + 0,2 vaut 0.30000000000000004 en virgule flottante
        $calculator = new PackCalculator();
        $noisy = 0.1 + 0.2;

        // Then : 0,3 kg contre 300 g, 0,3 l contre 30 cl et 0,3 kg contre 100 g (trois produits) sont pile
        $this->assertResult(1, self::COMPUTED, $calculator->packsFor(self::weight($noisy, 1000), self::content(300, ContentUnit::G)));
        $this->assertResult(1, self::COMPUTED, $calculator->packsFor(self::volume($noisy, 1000), self::content(30, ContentUnit::CL)));
        $this->assertResult(3, self::COMPUTED, $calculator->packsFor(self::weight($noisy, 1000), self::content(100, ContentUnit::G)));
        $this->assertResult(1, self::COMPUTED, $calculator->packsFor(self::package($noisy * 10 - 2), null));
        $this->assertResult(3, self::COMPUTED, $calculator->packsFor(self::units(0.1 + 0.2 + 2.7), self::content(1, ContentUnit::PIECE)));
    }

    public function testAnotherContentAndTheSameLineGiveTheirOwnCount(): void
    {
        // Given : 800 g de farine contre deux produits de contenu différent (le plan donne un nombre par produit)
        $calculator = new PackCalculator();
        $line = self::weight(800, 1);

        // Then
        $this->assertResult(2, self::COMPUTED, $calculator->packsFor($line, self::content(500, ContentUnit::G)));
        $this->assertResult(1, self::COMPUTED, $calculator->packsFor($line, self::content(1, ContentUnit::KG)));
        $this->assertResult(8, self::COMPUTED, $calculator->packsFor($line, self::content(100, ContentUnit::G)));
    }

    public function testTheContentUnitsKnowTheirDimensionAndTheirFactor(): void
    {
        $this->assertSame(
            [
                'g' => [Dimension::WEIGHT, 1.0],
                'kg' => [Dimension::WEIGHT, 1000.0],
                'ml' => [Dimension::VOLUME, 1.0],
                'cl' => [Dimension::VOLUME, 10.0],
                'l' => [Dimension::VOLUME, 1000.0],
                'piece' => [Dimension::COUNT, 1.0],
            ],
            array_combine(
                array_map(static fn(ContentUnit $unit): string => $unit->value, ContentUnit::cases()),
                array_map(static fn(ContentUnit $unit): array => [$unit->dimension(), $unit->factor()], ContentUnit::cases()),
            ),
        );
    }

    public function testThePackResultFactoriesSetTheStatus(): void
    {
        $this->assertEquals(new PackResult(2, PackStatus::COMPUTED), PackResult::computed(2));
        $this->assertEquals(new PackResult(null, PackStatus::TO_CHECK), PackResult::toCheck(null));
        $this->assertSame(['COMPUTED', 'TO_CHECK'], array_map(static fn(PackStatus $status): string => $status->value, PackStatus::cases()));
    }

    private function assertResult(?int $packs, string $status, PackResult $result): void
    {
        $this->assertSame([$packs, $status], [$result->packs, $result->status->value]);
    }

    private static function package(float $quantity): LineQuantity
    {
        return new LineQuantity($quantity, Dimension::PACKAGE, 1.0);
    }

    private static function units(float $quantity): LineQuantity
    {
        return new LineQuantity($quantity, Dimension::COUNT, 1.0);
    }

    private static function weight(float $quantity, float $baseFactor): LineQuantity
    {
        return new LineQuantity($quantity, Dimension::WEIGHT, $baseFactor);
    }

    private static function volume(float $quantity, float $baseFactor): LineQuantity
    {
        return new LineQuantity($quantity, Dimension::VOLUME, $baseFactor);
    }

    private static function content(float $value, ContentUnit $unit): ProductContent
    {
        return new ProductContent($value, $unit);
    }
}
