<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class UnitRepositoryTest extends DatabaseTestCase
{
    private readonly UnitRepository $unitRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unitRepository = static::getContainer()->get(UnitRepository::class);
    }

    public function testSaveAndGetOneBySlugAndDelete(): void
    {
        //Arrange
        $unit = new UnitBuilder()->setName('New unit')->build();

        //Act
        $this->unitRepository->save($unit);

        //Assert
        $loaded = $this->unitRepository->findOneBySlug($unit->getSlug());
        $this->assertEquals($unit, $loaded);

        //Act
        $this->unitRepository->delete($unit);

        //Assert
        $this->expectExceptionObject(UnitException::notFound());
        $this->unitRepository->findOneBySlug($unit->getSlug());
    }

    public function testLoadNonExistent(): void
    {
        //Assert
        $this->expectExceptionObject(UnitException::notFound());

        //Act
        $this->unitRepository->findOneBySlug("non-existent");
    }
}
