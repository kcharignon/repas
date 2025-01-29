<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Tests\Builder\DepartmentBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class DepartmentRepositoryTest extends DatabaseTestCase
{
    private readonly DepartmentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(DepartmentRepository::class);
    }

    public function testInsertAndUpdateAndFindBySlug(): void
    {
        //Arrange
        $department = new DepartmentBuilder()->build();

        //Act
        $this->repository->save($department);

        //Assert
        $loadedDepartment = $this->repository->getOneBySlug($department->getSlug());
        $this->assertEquals([
            'slug' => 'maxi-outils',
            'name' => 'Maxi Outils',
            'image' => 'file://images/maxi-outils.jpg',
        ], $loadedDepartment->toArray());

        //Assert
        $department->setName('Mega Outils');
        $department->setImage('file://images/mega-outils.jpg');

        //Act
        $this->repository->save($department);

        //Assert
        $loadedDepartment = $this->repository->getOneBySlug($department->getSlug());
        $this->assertEquals([
            'slug' => 'maxi-outils',
            'name' => 'Mega Outils',
            'image' => 'file://images/mega-outils.jpg',
        ], $loadedDepartment->toArray());
    }

    public function testFindBySlugThenNotFound(): void
    {
        //Assert
        $this->expectExceptionObject(DepartmentException::notFound());

        //Act
        $this->repository->getOneBySlug('not-found');

    }


}
