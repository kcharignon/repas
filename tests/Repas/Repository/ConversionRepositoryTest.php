<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Tests\Builder\ConversionBuilder;
use Repas\Tests\Builder\IngredientBuilder;
use Repas\Tests\Builder\UnitBuilder;
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
        $eggConversions = $this->conversionRepository->findByIngredient($egg);
        $actual = $eggConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        RepasAssert::assertConversion($conversion, $actual);

        // Arrange
        $milk = new IngredientBuilder()->isMilk()->build();
        $conversion->update(
            startUnit: new UnitBuilder()->isKilo()->build(),
            endUnit: new UnitBuilder()->isCentilitre()->build(),
            coefficient: 100,
            ingredient: $milk,
        );

        // Act
        $this->conversionRepository->save($conversion);

        // Assert
        $eggConversions = $this->conversionRepository->findByIngredient($egg);
        $actual = $eggConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        $this->assertNull($actual);

        $milkConversions = $this->conversionRepository->findByIngredient($milk);
        $actual = $milkConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        RepasAssert::assertConversion($conversion, $actual);
    }
}
