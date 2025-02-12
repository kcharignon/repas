<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CreateUnit\CreateUnitCommand;
use Repas\Repas\Application\CreateUnit\CreateUnitHandler;
use Repas\Repas\Domain\Interface\UnitRepository;
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
}
