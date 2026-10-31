<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitCommand;
use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitHandler;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Dimension;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UpdateUnitHandlerTest extends TestCase
{
    private UpdateUnitHandler $handler;
    private UnitRepository $unitRepository;

    protected function setUp(): void
    {

        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isLiter()->build()
        ]);
        $this->handler = new UpdateUnitHandler($this->unitRepository);
    }

    public function testHandleSuccessfullyUpdateUnit(): void
    {
        // Arrange : sans les champs de mesure, la dimension et le facteur de base de l'unité restent ce qu'ils étaient
        $command = new UpdateUnitCommand(
            "litre",
            "nouveau nom",
            "$",
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new UnitBuilder()
            ->withSlug("litre")
            ->withName("nouveau nom")
            ->withSymbol("$")
            ->withMeasure(Dimension::VOLUME, 1000.0)
            ->build();
        $actual = $this->unitRepository->findOneBySlug("litre");
        RepasAssert::assertUnit($expected, $actual);
    }

    public function testReplacesTheMeasureWhenTheCommandCarriesIt(): void
    {
        // Arrange
        $command = new UpdateUnitCommand('litre', 'litre', 'l', replaceMeasure: true, dimension: Dimension::WEIGHT, baseFactor: 1.5);

        // Act
        ($this->handler)($command);

        // Assert
        $actual = $this->unitRepository->findOneBySlug('litre');
        $this->assertSame([Dimension::WEIGHT, 1.5], [$actual->getDimension(), $actual->getBaseFactor()]);
    }

    public function testClearsTheMeasureWhenTheCommandCarriesNothing(): void
    {
        // Arrange
        $command = new UpdateUnitCommand('litre', 'litre', 'l', replaceMeasure: true, dimension: null, baseFactor: null);

        // Act
        ($this->handler)($command);

        // Assert
        $actual = $this->unitRepository->findOneBySlug('litre');
        $this->assertSame([null, null], [$actual->getDimension(), $actual->getBaseFactor()]);
    }

    public function testRefusesAnIncompleteMeasureAndKeepsTheUnit(): void
    {
        // Arrange
        $command = new UpdateUnitCommand('litre', 'autre nom', 'x', replaceMeasure: true, dimension: Dimension::WEIGHT, baseFactor: null);

        // Assert
        $this->expectExceptionObject(UnitException::incompleteMeasure());

        try {
            // Act
            ($this->handler)($command);
        } finally {
            $unit = $this->unitRepository->findOneBySlug('litre');
            $this->assertSame(['litre', Dimension::VOLUME, 1000.0], [$unit->getName(), $unit->getDimension(), $unit->getBaseFactor()]);
        }
    }

    public function testRefusesABaseFactorWhichIsNotPositive(): void
    {
        // Arrange
        $command = new UpdateUnitCommand('litre', 'litre', 'l', replaceMeasure: true, dimension: Dimension::VOLUME, baseFactor: 0.0);

        // Assert
        $this->expectExceptionObject(UnitException::invalidBaseFactor());

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedUpdateUnitUnknownUnit(): void
    {
        // Arrange
        $command = new UpdateUnitCommand(
            "existe-pas",
            "nouveau nom",
            "$",
        );

        // Assert
        $this->expectExceptionObject(UnitException::notFound("existe-pas"));

        // Act
        ($this->handler)($command);
    }
}
