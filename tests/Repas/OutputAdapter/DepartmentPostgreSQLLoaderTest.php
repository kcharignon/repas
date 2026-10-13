<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class DepartmentPostgreSQLLoaderTest extends DatabaseTestCase
{
    private DepartmentRepository $departmentRepository;
    private DepartmentLoader $departmentLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->departmentRepository = static::getContainer()->get(DepartmentRepository::class);
        $this->departmentLoader = static::getContainer()->get(DepartmentLoader::class);
    }

    public function testListReturnsNothingWhenThereIsNone(): void
    {
        // When
        $result = $this->departmentLoader->list();

        // Then
        $this->assertSame([], $result);
    }

    public function testListReturnsDTOsOfCreatedDepartments(): void
    {
        // Given
        $this->departmentRepository->save(
            new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build()
        );

        // When
        $result = $this->departmentLoader->list();

        // Then
        $this->assertContainsOnlyInstancesOf(DepartmentDTO::class, $result);
        $this->assertEquals(
            new DepartmentDTO('zzz-outils', 'Zzz Outils', 'file://images/zzz.jpg'),
            $this->findBySlug($result, 'zzz-outils'),
        );
    }

    public function testListIsOrderedBySlug(): void
    {
        // Given
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Aaa Outils')->build());
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('aaa-outils')->withName('Zzz Outils')->build());

        // When
        $result = $this->departmentLoader->list();

        // Then
        $slugs = array_map(static fn(DepartmentDTO $department): string => $department->slug, $result);
        $this->assertLessThan(array_search('zzz-outils', $slugs), array_search('aaa-outils', $slugs));
    }

    /**
     * @param list<DepartmentDTO> $departments
     */
    private function findBySlug(array $departments, string $slug): ?DepartmentDTO
    {
        foreach ($departments as $department) {
            if ($department->slug === $slug) {
                return $department;
            }
        }

        return null;
    }
}
