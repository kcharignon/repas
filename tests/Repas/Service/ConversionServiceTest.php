<?php

namespace Repas\Tests\Repas\Service;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class ConversionServiceTest extends TestCase
{
    private ConversionService $conversionService;

    protected function setUp(): void
    {
        parent::setUp();

        $conversions = $this->generateConversions();
        $conversionRepository = new ConversionInMemoryRepository($conversions);
        $units = $this->generateUnits();
        $unitRepository = new UnitInMemoryRepository($units);
        $this->conversionService = new ConversionService($conversionRepository, $unitRepository);
    }

    public static function convertToPurchaseUnitSuccessDataProvider(): array
    {
        $egg = new IngredientBuilder()->isEgg()->build();
        $box = new UnitBuilder()->isBox()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
        $piece = new UnitBuilder()->isUnite()->build();
        $millilitre = new UnitBuilder()->isMillilitre()->build();
        $centilitre = new UnitBuilder()->isCentiliter()->build();
        return [
            "0 step same unit" => [$egg, 5, $piece, 5],
            "1 step Box =(simple)=> Piece" => [$egg, 3, $box, 36],
            "1 step Gramme =(reverse)=> Piece" => [$egg, 240, $gramme, 4],
            "2 steps Millilitre =(simple)=> Gramme =(reverse)=> Piece" => [$egg, 400, $millilitre, 10],
            "3 steps Centilitre =(simple)=> Millilitre =(simple)=> Gramme =(reverse)=> Piece" => [$egg, 40, $centilitre, 10],
        ];
    }

    #[DataProvider('convertToPurchaseUnitSuccessDataProvider')]
    public function testConvertToPurchaseUnitSuccess(Ingredient $ingredient, float $quantity, Unit $unit, float $expected): void
    {
        // Act
        $actual = $this->conversionService->convertToPurchaseUnit($ingredient, $quantity, $unit);

        // Assert
        $this->assertEquals($expected, $actual);
    }

    public function testConvertToPurchaseUnitFailed(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $unit = new UnitBuilder()->isKilo()->build();

        // Assert
        $this->expectExceptionObject(IngredientException::cannotConvertToUnit($egg, $unit, $egg->getDefaultPurchaseUnit()));

        // Act
        $actual = $this->conversionService->convertToPurchaseUnit($egg, 25, $unit);
    }

    public function testTheGraphIsRebuiltAfterClear(): void
    {
        // Given : le graphe de l'œuf est déjà construit, le kilo ne mène nulle part
        $conversionRepository = new ConversionInMemoryRepository($this->generateConversions());
        $service = new ConversionService($conversionRepository, new UnitInMemoryRepository($this->generateUnits()));
        $egg = new IngredientBuilder()->isEgg()->build();
        $kilo = new UnitBuilder()->isKilo()->build();
        $this->assertFalse($this->canConvert($service, $egg, $kilo));

        // When : une conversion commune kilo => gramme est ajoutée, le service ne la voit pas tant que le graphe est en cache
        $conversionRepository->save(new ConversionBuilder()
            ->withStartUnit($kilo)
            ->withEndUnit(new UnitBuilder()->isGramme()->build())
            ->withCoefficient(1000)
            ->build());
        $this->assertFalse($this->canConvert($service, $egg, $kilo));
        $service->clear();

        // Then
        $this->assertTrue($this->canConvert($service, $egg, $kilo));
    }

    public function testCanConvertAsksTheGraphOfTheIngredientBetweenAnyTwoUnits(): void
    {
        // Given : l'œuf, dont le graphe relie la boîte, la pièce, le gramme, le millilitre et le centilitre
        $egg = new IngredientBuilder()->isEgg()->build();
        $box = new UnitBuilder()->isBox()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();
        $slice = new UnitBuilder()->isSlice()->build();

        // Then
        $this->assertTrue($this->conversionService->canConvert($egg, $box, $centiliter));
        $this->assertTrue($this->conversionService->canConvert($egg, $box, $box), 'une unité se convertit en elle-même');
        $this->assertFalse($this->conversionService->canConvert($egg, $slice, $box));
        $this->assertTrue($this->conversionService->canConvertToPurchaseUnit($egg), "l'unité des recettes de l'œuf est son unité d'achat");
        $this->conversionService->assertConvertible($egg, $box, $centiliter);

        $this->expectExceptionObject(IngredientException::cannotConvertToUnit($egg, $slice, $box));
        $this->conversionService->assertConvertible($egg, $slice, $box);
    }

    public function testCanConvertWithAnsweresForAHypotheticalSetOfConversionsWithoutReadingAnything(): void
    {
        // Given : les conversions que la farine aurait si on en retirait une
        $flour = new IngredientBuilder()->isFlour()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
        $kilo = new UnitBuilder()->isKilo()->build();
        $cup = new UnitBuilder()->withName('tasse')->build();
        $kiloToGramme = new ConversionBuilder()->isKiloToGramme()->build();
        $cupToGramme = new ConversionBuilder()->withIngredient($flour)->withStartUnit($cup)->withEndUnit($gramme)->withCoefficient(125)->build();

        // Then : avec les deux, la tasse mène au kilo ; sans l'universelle, non ; sans rien, une unité se convertit seulement en elle-même
        $this->assertTrue($this->conversionService->canConvertWith([$kiloToGramme, $cupToGramme], $cup, $kilo));
        $this->assertFalse($this->conversionService->canConvertWith([$cupToGramme], $cup, $kilo));
        $this->assertTrue($this->conversionService->canConvertWith(new \ArrayIterator([$cupToGramme]), $cup, $gramme));
        $this->assertFalse($this->conversionService->canConvertWith([], $cup, $gramme));
        $this->assertTrue($this->conversionService->canConvertWith([], $cup, $cup));
    }

    private function canConvert(ConversionService $service, Ingredient $ingredient, Unit $unit): bool
    {
        try {
            $service->convertToPurchaseUnit($ingredient, 1, $unit);
        } catch (IngredientException) {
            return false;
        }

        return true;
    }

    public static function getConvertibleUnitDataProvider(): array
    {
        $egg = new IngredientBuilder()->isEgg()->build();
        $kilo  = new UnitBuilder()->isKilo()->build();
        $box = new UnitBuilder()->isBox()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
        $piece = new UnitBuilder()->isUnite()->build();
        $millilitre = new UnitBuilder()->isMillilitre()->build();
        $centilitre = new UnitBuilder()->isCentiliter()->build();
        return [
            "unit without conversion" => [$egg, $kilo, Tab::fromArray($kilo)],
            "unit with conversion" => [$egg, $centilitre, Tab::fromArray($box, $gramme, $piece, $millilitre, $centilitre)],
        ];
    }

    #[DataProvider('getConvertibleUnitDataProvider')]
    public function testGetConvertibleUnits(Ingredient $ingredient, Unit $unit, Tab $expected): void
    {
        // Act
        $actual = $this->conversionService->getConvertibleUnits($ingredient, $unit);

        // Expected
        RepasAssert::assertTab(
            $expected,
            $actual,
            fn(Unit $a, Unit $b) => $a->getSlug() <=> $b->getSlug(),
            function ($expected, $actual) {
                RepasAssert::assertUnit($expected, $actual);
            }
        );
    }

    /**
     * @return array<Conversion>
     */
    private function generateConversions(): array
    {
        $egg = new IngredientBuilder()->isEgg()->build();
        $milk = new IngredientBuilder()->isMilk()->build();
        return [
            new ConversionBuilder()
                ->withIngredient($egg)
                ->withStartUnit(new UnitBuilder()->isBox()->build())
                ->withEndUnit(new UnitBuilder()->isUnite()->build())
                ->withCoefficient(12)
                ->build(),
            new ConversionBuilder()
                ->withIngredient($egg)
                ->withStartUnit(new UnitBuilder()->isUnite()->build())
                ->withEndUnit(new UnitBuilder()->isGramme()->build())
                ->withCoefficient(60)
                ->build(),
            new ConversionBuilder()
                ->withIngredient($egg)
                ->withStartUnit(new UnitBuilder()->isMillilitre()->build())
                ->withEndUnit(new UnitBuilder()->isGramme()->build())
                ->withCoefficient(1.5)
                ->build(),
            new ConversionBuilder()
                ->withStartUnit(new UnitBuilder()->isCentiliter()->build())
                ->withEndUnit(new UnitBuilder()->isMillilitre()->build())
                ->withCoefficient(10)
                ->build(),
            new ConversionBuilder()
                ->withIngredient($milk)
                ->withStartUnit(new UnitBuilder()->isMillilitre()->build())
                ->withEndUnit(new UnitBuilder()->isUnite()->build())
                ->withCoefficient(400)
                ->build(),
        ];
    }

    /**
     * @return array<Unit>
     */
    private function generateUnits(): array
    {
        return [
            new UnitBuilder()->isBox()->build(),
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isUnite()->build(),
            new UnitBuilder()->isMillilitre()->build(),
            new UnitBuilder()->isCentiliter()->build(),
            new UnitBuilder()->isKilo()->build(),
        ];
    }
}
