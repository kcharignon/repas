<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateUnit\CreateUnitCommand;
use Repas\Repas\Application\Command\CreateUnit\CreateUnitHandler;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Shared\Domain\Model\Dimension;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class CreateUnitHandlerTest extends TestCase
{
    private readonly CreateUnitHandler $handler;
    private readonly UnitRepository $unitRepository;

    protected function setUp(): void
    {
        $this->unitRepository = new UnitInMemoryRepository();
        $this->handler = new CreateUnitHandler($this->unitRepository);
    }

    public function testHandleSuccessfullyCreateUnit(): void
    {
        // Arrange
        $command = new CreateUnitCommand("nouvelle unite", "symbol");

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new UnitBuilder()
            ->withName("nouvelle unite")
            ->withSymbol("symbol")
            ->build();
        $actual = $this->unitRepository->findOneBySlug("nouvelle-unite");
        RepasAssert::assertUnit($expected, $actual);
    }

    public function testReturnsTheSlugOfTheCreatedUnit(): void
    {
        // Act
        $slug = ($this->handler)(new CreateUnitCommand('nouvelle unite', 'symbol'));

        // Assert
        $this->assertSame('nouvelle-unite', $slug);
    }

    public function testCreatesAnUnitWithItsMeasure(): void
    {
        // Arrange
        $command = new CreateUnitCommand('kilo bis', 'kg', Dimension::WEIGHT, 1000.0);

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new UnitBuilder()->withName('kilo bis')->withSymbol('kg')->withMeasure(Dimension::WEIGHT, 1000.0)->build();
        RepasAssert::assertUnit($expected, $this->unitRepository->findOneBySlug('kilo-bis'));
    }

    public function testCreatesAnUnitWithoutMeasureByDefault(): void
    {
        // Act
        ($this->handler)(new CreateUnitCommand('pincée', 'pin'));

        // Assert
        $unit = $this->unitRepository->findOneBySlug('pincee');
        $this->assertSame([null, null], [$unit->getDimension(), $unit->getBaseFactor()]);
    }

    public function testRefusesADimensionWithoutBaseFactorAndSavesNothing(): void
    {
        // Assert
        $this->expectExceptionObject(UnitException::incompleteMeasure());

        try {
            // Act
            ($this->handler)(new CreateUnitCommand('pincée', 'pin', Dimension::COUNT, null));
        } finally {
            $this->assertFalse($this->unitRepository->exists('pincee'));
        }
    }

    public function testRefusesABaseFactorWithoutDimension(): void
    {
        // Assert
        $this->expectExceptionObject(UnitException::incompleteMeasure());

        // Act
        ($this->handler)(new CreateUnitCommand('pincée', 'pin', null, 1.0));
    }

    public function testRefusesABaseFactorWhichIsNotPositive(): void
    {
        // Assert
        $this->expectExceptionObject(UnitException::invalidBaseFactor());

        // Act
        ($this->handler)(new CreateUnitCommand('pincée', 'pin', Dimension::COUNT, -1.0));
    }

    public function testRefusesAnUnitWhoseSlugAlreadyExists(): void
    {
        // Arrange : un second « Nouvelle unité » écraserait la première
        ($this->handler)(new CreateUnitCommand('nouvelle unite', 'symbol'));

        // Assert
        $this->expectExceptionObject(UnitException::alreadyExists('nouvelle-unite'));

        // Act
        ($this->handler)(new CreateUnitCommand('Nouvelle unité', 'autre'));
    }
}
