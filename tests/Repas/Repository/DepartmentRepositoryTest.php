<?php

namespace Repas\Repository;


use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;

class DepartmentRepositoryTest extends DatabaseTestCase
{
    private readonly DepartmentRepository $departmentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->departmentRepository = static::getContainer()->get(DepartmentRepository::class);
    }

    public function testInsertAndUpdateAndFindBySlug(): void
    {
        //Arrange
        $department = new DepartmentBuilder()->build();

        //Act
        $this->departmentRepository->save($department);

        //Assert
        $loadedDepartment = $this->departmentRepository->findOneBySlug($department->getSlug());
        $this->assertEquals([
            'slug' => 'maxi-outils',
            'name' => 'Maxi Outils',
            'image' => 'file://images/maxi-outils.jpg',
        ], $loadedDepartment->toArray());

        //Assert
        $department->setName('Mega Outils');
        $department->setImage('file://images/mega-outils.jpg');

        //Act
        $this->departmentRepository->save($department);

        //Assert
        $loadedDepartment = $this->departmentRepository->findOneBySlug($department->getSlug());
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
        $this->departmentRepository->findOneBySlug('not-found');

    }

    public function testFindAll(): void
    {
        // Act
        $departments = $this->departmentRepository->findAll();

        // Assert
        $this->assertCount(20, $departments);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Department::class), $departments);
    }
}
