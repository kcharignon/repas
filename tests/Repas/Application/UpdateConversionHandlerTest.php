<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Application\UpdateConversion\UpdateConversionHandler;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UpdateConversionHandlerTest extends TestCase
{
    private UpdateConversionHandler $handler;
    private ConversionRepository $conversionRepository;
    private UnitRepository $unitRepository;
    private IngredientRepository $ingredientRepository;

    protected function setUp(): void
    {
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentilitre()->build();
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()
                ->withId("unique-id-with-ingredient")
                ->isUnitToGrammeForEgg()
                ->build(),
            new ConversionBuilder()
                ->withId("unique-id-without-ingredient")
                ->withStartUnit($liter)
                ->withEndUnit($centiliter)
                ->withCoefficient(100)
                ->withoutIngredient()
                ->build(),
            new ConversionBuilder()->build()
        ]);
        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isUnite()->build(),
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isBox()->build(),
            $liter,
            $centiliter,
        ]);
        $this->ingredientRepository = new IngredientInMemoryRepository([
            new IngredientBuilder()->isEgg()->build(),
        ]);

        $this->handler = new UpdateConversionHandler(
            $this->conversionRepository,
            $this->unitRepository,
            $this->ingredientRepository,
        );
    }

    public function testHandleSuccessfullyUpdateConversionWithIngredient(): void
    {
        // Arrange
        $id = 'unique-id-with-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: $egg->getSlug(),
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withIngredient($egg)
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }


    public function testHandleSuccessfullyUpdateConversionRemoveIngredient(): void
    {
        // Arrange
        $id = 'unique-id-with-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: null,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withoutIngredient()
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testHandleSuccessfullyUpdateConversionWithoutIngredient(): void
    {
        // Arrange
        $id = 'unique-id-without-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: null,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withoutIngredient()
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testHandleSuccessfullyUpdateConversionAddIngredient(): void
    {
        // Arrange
        $id = 'unique-id-without-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: $egg->getSlug(),
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withIngredient($egg)
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }
}
