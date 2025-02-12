<?php

namespace Repas\Tests\Repas\Service;


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

class ConversionServiceTest extends TestCase
{
    private ConversionService $conversionService;

    protected function setUp(): void
    {
        parent::setUp();

        $conversions = $this->generateConversionTab();
        $conversionRepository = new ConversionInMemoryRepository($conversions);
        $this->conversionService = new ConversionService($conversionRepository);
    }

    public function convertToPurchaseUnitSuccessDataProvider(): array
    {
        $egg = new IngredientBuilder()->isEgg()->build();
        $box = new UnitBuilder()->isBox()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
        $piece = new UnitBuilder()->isUnite()->build();
        $millilitre = new UnitBuilder()->isMillilitre()->build();
        $centilitre = new UnitBuilder()->isCentilitre()->build();
        return [
            "0 step same unit" => [$egg, 5, $piece, 5],
            "1 step Box =(simple)=> Piece" => [$egg, 3, $box, 36],
            "1 step Gramme =(reverse)=> Piece" => [$egg, 240, $gramme, 4],
            "2 steps Millilitre =(simple)=> Gramme =(reverse)=> Piece" => [$egg, 400, $millilitre, 10],
            "3 steps Centilitre =(simple)=> Millilitre =(simple)=> Gramme =(reverse)=> Piece" => [$egg, 40, $centilitre, 10],
        ];
    }

    /**
     * @dataProvider convertToPurchaseUnitSuccessDataProvider
     */
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


    /**
     * @return Tab<Conversion>
     */
    private function generateConversionTab(): Tab
    {
        $egg = new IngredientBuilder()->isEgg()->build();
        $milk = new IngredientBuilder()->isMilk()->build();
        return Tab::fromArray([
            new ConversionBuilder()
                ->setIngredient($egg)
                ->setStartUnit(new UnitBuilder()->isBox()->build())
                ->setEndUnit(new UnitBuilder()->isUnite()->build())
                ->setCoefficient(12)
                ->build(),
            new ConversionBuilder()
                ->setIngredient($egg)
                ->setStartUnit(new UnitBuilder()->isUnite()->build())
                ->setEndUnit(new UnitBuilder()->isGramme()->build())
                ->setCoefficient(60)
                ->build(),
            new ConversionBuilder()
                ->setIngredient($egg)
                ->setStartUnit(new UnitBuilder()->isMillilitre()->build())
                ->setEndUnit(new UnitBuilder()->isGramme()->build())
                ->setCoefficient(1.5)
                ->build(),
            new ConversionBuilder()
                ->setStartUnit(new UnitBuilder()->isCentilitre()->build())
                ->setEndUnit(new UnitBuilder()->isMillilitre()->build())
                ->setCoefficient(10)
                ->build(),
            new ConversionBuilder()
                ->setIngredient($milk)
                ->setStartUnit(new UnitBuilder()->isMillilitre()->build())
                ->setEndUnit(new UnitBuilder()->isUnite()->build())
                ->setCoefficient(400)
                ->build(),
        ]);
    }
}
