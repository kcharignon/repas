<?php

namespace Repas\Repository;


use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\DepartmentIcon;
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
            'icon' => null,
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
            'icon' => null,
        ], $loadedDepartment->toArray());
    }

    public function testTheIconIsSavedChangedAndErasedAndAModificationOfTheNameNeverTouchesIt(): void
    {
        // Arrange : un rayon sans icône
        $department = new DepartmentBuilder()->build();
        $this->departmentRepository->save($department);
        $this->assertNull($this->departmentRepository->findOneBySlug($department->getSlug())->getIcon());

        // Act : une icône, puis un autre nom
        $department->changeIcon(DepartmentIcon::SNOWFLAKE);
        $this->departmentRepository->save($department);
        $department->setName('Mega Outils');
        $this->departmentRepository->save($department);

        // Assert : l'icône est relue, et une icône que `update()` ne connaît pas n'est pas effacée par lui
        $loaded = $this->departmentRepository->findOneBySlug($department->getSlug());
        $this->assertSame(DepartmentIcon::SNOWFLAKE, $loaded->getIcon());
        $loaded->update('Autre nom', 'file://images/autre.jpg');
        $this->departmentRepository->save($loaded);
        $this->assertSame(DepartmentIcon::SNOWFLAKE, $this->departmentRepository->findOneBySlug($department->getSlug())->getIcon());

        // Act : l'icône est changée, puis effacée
        $loaded->changeIcon(DepartmentIcon::CAN);
        $this->departmentRepository->save($loaded);
        $this->assertSame(DepartmentIcon::CAN, $this->departmentRepository->findOneBySlug($department->getSlug())->getIcon());
        $loaded->changeIcon(null);
        $this->departmentRepository->save($loaded);

        // Assert
        $this->assertNull($this->departmentRepository->findOneBySlug($department->getSlug())->getIcon());
    }

    public function testAnIconStoredByANewerVersionThatThisOneDoesNotKnowReadsAsNoIcon(): void
    {
        // Arrange : une valeur écrite directement en base
        $department = new DepartmentBuilder()->build();
        $this->departmentRepository->save($department);
        $this->entityManager->getConnection()->executeStatement("UPDATE department SET icon = 'bag' WHERE slug = ?", [$department->getSlug()]);
        $this->entityManager->clear();

        // Act & Assert : la lecture n'échoue pas
        $this->assertNull($this->departmentRepository->findOneBySlug($department->getSlug())->getIcon());
    }

    public function testFindBySlugThenNotFound(): void
    {
        //Assert
        $this->expectExceptionObject(DepartmentException::notFound());

        //Act
        $this->departmentRepository->findOneBySlug('not-found');

    }

    public function testFindAllContainsSavedDepartments(): void
    {
        // Arrange
        $first = new DepartmentBuilder()->withSlug('aaa-outils')->withName('Aaa Outils')->build();
        $second = new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->build();
        $this->departmentRepository->save($first);
        $this->departmentRepository->save($second);

        // Act
        $departments = $this->departmentRepository->findAll();

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Department::class), $departments);
        $slugs = array_map(fn(Department $department) => $department->getSlug(), $departments->toArray());
        $this->assertContains('aaa-outils', $slugs);
        $this->assertContains('zzz-outils', $slugs);
    }
}
