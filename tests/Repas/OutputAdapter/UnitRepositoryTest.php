<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Shared\Domain\Model\Dimension;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class UnitRepositoryTest extends DatabaseTestCase
{
    private readonly UnitRepository $unitRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unitRepository = static::getContainer()->get(UnitRepository::class);
    }

    public function testSaveAndFindOneBySlugAndDelete(): void
    {
        //Arrange
        $unit = new UnitBuilder()->withName('New unit')->build();

        //Act
        $this->unitRepository->save($unit);

        //Assert
        $loaded = $this->unitRepository->findOneBySlug($unit->getSlug());
        $this->assertEquals($unit, $loaded);

        //Act
        $this->unitRepository->delete($unit);

        //Assert
        $this->expectExceptionObject(UnitException::notFound($unit->getSlug()));
        $this->unitRepository->findOneBySlug($unit->getSlug());
    }

    public function testTheMeasureSurvivesTheRoundTripAndItsUpdate(): void
    {
        // Arrange
        $unit = new UnitBuilder()->withName('Pot de test')->withMeasure(Dimension::PACKAGE, 1.0)->build();
        $this->unitRepository->save($unit);
        $this->entityManager->clear();

        // Assert : relue de la base telle quelle
        $loaded = $this->unitRepository->findOneBySlug($unit->getSlug());
        $this->assertSame([Dimension::PACKAGE, 1.0], [$loaded->getDimension(), $loaded->getBaseFactor()]);

        // Act : la mesure est remplacée
        $loaded->setMeasure(Dimension::WEIGHT, 0.5);
        $this->unitRepository->save($loaded);
        $this->entityManager->clear();

        // Assert
        $replaced = $this->unitRepository->findOneBySlug($unit->getSlug());
        $this->assertSame([Dimension::WEIGHT, 0.5], [$replaced->getDimension(), $replaced->getBaseFactor()]);

        // Act : puis effacée
        $replaced->setMeasure(null, null);
        $this->unitRepository->save($replaced);
        $this->entityManager->clear();

        // Assert
        $cleared = $this->unitRepository->findOneBySlug($unit->getSlug());
        $this->assertSame([null, null], [$cleared->getDimension(), $cleared->getBaseFactor()]);
    }

    public function testLoadNonExistent(): void
    {
        //Assert
        $this->expectExceptionObject(UnitException::notFound("non-existent"));

        //Act
        $this->unitRepository->findOneBySlug("non-existent");
    }
}
