<?php

namespace Repas;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Infrastructure\Repository\UnitPostgreSQLRepository;
use Repas\Tests\Helper\DatabaseTestCase;

class UnitRepositoryTest extends DatabaseTestCase
{
    private UnitRepository $unitRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $managerRegistry = static::getContainer()->get('doctrine');

        $this->unitRepository = new UnitPostgreSQLRepository($managerRegistry);
    }

    public function testInsert(): void
    {
        //Arrange
        $unit = Unit::create("new unit","nu");

        //Act
        $this->unitRepository->save($unit);

        //Assert
        $loaded = $this->unitRepository->findBySlug($unit->getSlug());
        $this->assertEquals($unit->toArray(), $loaded->toArray());
    }

    public function testLoadNonExistent(): void
    {
        //Assert
        $this->expectExceptionObject(UnitException::notFound());

        //Act
        $this->unitRepository->findBySlug("non-existent");
    }

}
