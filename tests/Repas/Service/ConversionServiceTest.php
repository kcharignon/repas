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
