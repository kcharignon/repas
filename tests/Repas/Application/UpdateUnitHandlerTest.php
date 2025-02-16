<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateUnit\UpdateUnitCommand;
use Repas\Repas\Application\UpdateUnit\UpdateUnitHandler;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
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
        // Arrange
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
            ->build();
        $actual = $this->unitRepository->findOneBySlug("litre");
        RepasAssert::assertUnit($expected, $actual);
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
