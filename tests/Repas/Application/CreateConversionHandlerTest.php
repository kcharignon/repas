<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CreateConversion\CreateConversionCommand;
use Repas\Repas\Application\CreateConversion\CreateConversionHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class CreateConversionHandlerTest extends TestCase
{
    private CreateConversionHandler $handler;
    private ConversionRepository $conversionRepository;
    private UnitRepository $unitRepository;
    private IngredientRepository $ingredientRepository;

    protected function setUp(): void
    {
        $this->conversionRepository = new ConversionInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();

        $this->handler = new CreateConversionHandler(
            $this->conversionRepository,
            $this->unitRepository,
            $this->ingredientRepository,
            new ControlledUuidGenerator(),
        );
    }

    public function testHandleSuccessfullyCreateConversionWithIngredient(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new CreateConversionCommand($box->getSlug(), $unite->getSlug(), 6, $egg->getSlug());

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId(ControlledUuidGenerator::UUID)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withIngredient($egg)
            ->build();
        $actual = $this->conversionRepository->findByIngredient($egg)->reset();
        RepasAssert::assertConversion($expected, $actual);
    }


    public function testHandleSuccessfullyCreateConversionWithoutIngredient(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);

        $command = new CreateConversionCommand($box->getSlug(), $unite->getSlug(), 6, null);

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId(ControlledUuidGenerator::UUID)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withoutIngredient()
            ->build();
        $actual = $this->conversionRepository->findAll()->reset();
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testHandleFailedCreateConversionUnknownIngredient(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);
        $egg = new IngredientBuilder()->isEgg()->build();

        $command = new CreateConversionCommand($box->getSlug(), $unite->getSlug(), 6, $egg->getSlug());

        // Assert
        $this->expectExceptionObject(IngredientException::notFound($egg->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedCreateConversionUnknownStartUnit(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new CreateConversionCommand($box->getSlug(), $unite->getSlug(), 6, $egg->getSlug());

        // Assert
        $this->expectExceptionObject(UnitException::notFound($box->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedCreateConversionUnknownEndUnit(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new CreateConversionCommand($box->getSlug(), $unite->getSlug(), 6, $egg->getSlug());

        // Assert
        $this->expectExceptionObject(UnitException::notFound($unite->getSlug()));

        // Act
        ($this->handler)($command);
    }
}
