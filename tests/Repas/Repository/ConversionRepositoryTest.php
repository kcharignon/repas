<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;

class ConversionRepositoryTest extends DatabaseTestCase
{
    private ConversionRepository $conversionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversionRepository = static::getContainer()->get(ConversionRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $conversion = new ConversionBuilder()->build();

        // Act
        $this->conversionRepository->save($conversion);

        // Assert
        $eggConversions = $this->conversionRepository->findByIngredientOrCommon($egg);
        $actual = $eggConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        RepasAssert::assertConversion($conversion, $actual);

        // Arrange
        $milk = new IngredientBuilder()->isMilk()->build();
        $conversion->update(
            startUnit: new UnitBuilder()->isKilo()->build(),
            endUnit: new UnitBuilder()->isCentiliter()->build(),
            coefficient: 100,
            ingredient: $milk,
        );

        // Act
        $this->conversionRepository->save($conversion);

        // Assert
        $eggConversions = $this->conversionRepository->findByIngredientOrCommon($egg);
        $actual = $eggConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        $this->assertNull($actual);

        $milkConversions = $this->conversionRepository->findByIngredientOrCommon($milk);
        $actual = $milkConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        RepasAssert::assertConversion($conversion, $actual);
    }

    public function testFindAll(): void
    {
        // Act
        $conversions = $this->conversionRepository->findAll();

        // Arrange
        $this->assertCount(67, $conversions);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Conversion::class), $conversions);
    }

    public function testFindByIngredientAndStartUnitAndEndUnit(): void
    {
        // Act
        $actual = $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit(
            new IngredientBuilder()->isButter()->build(),
            new UnitBuilder()->isBlock()->build(),
            new UnitBuilder()->isGramme()->build(),
        );

        // Assert
        $expected = new ConversionBuilder()
            ->isBlockToGrammeForButter()
            ->build();
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testFindById(): void
    {
        // Act
        $actual = $this->conversionRepository->findById("9967014a-7c8b-423f-9b96-04fc13943a05");

        // Assert
        $expected = new ConversionBuilder()
            ->isBlockToGrammeForButter()
            ->build();
        RepasAssert::assertConversion($expected, $actual);
    }
}
