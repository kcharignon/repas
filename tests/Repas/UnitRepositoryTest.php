<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class UnitRepositoryTest extends DatabaseTestCase
{
    private UnitRepository $unitRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unitRepository = static::getContainer()->get(UnitRepository::class);
    }

    public function testInsertAndFindBySlugAndRemove(): void
    {
        //Arrange
        $unit = new UnitBuilder()->setName('New unit')->build();

        //Act
        $this->unitRepository->save($unit);

        //Assert
        $loaded = $this->unitRepository->findBySlug($unit->getSlug());
        $this->assertEquals($unit->toArray(), $loaded->toArray());

        //Act
        $this->unitRepository->delete($unit);

        //Assert
        $this->expectExceptionObject(UnitException::notFound());
        $this->unitRepository->findBySlug($unit->getSlug());
    }

    public function testLoadNonExistent(): void
    {
        //Assert
        $this->expectExceptionObject(UnitException::notFound());

        //Act
        $this->unitRepository->findBySlug("non-existent");
    }
}
