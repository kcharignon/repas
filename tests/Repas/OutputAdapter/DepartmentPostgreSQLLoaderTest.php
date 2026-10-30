<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\DepartmentIcon;
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
            new DepartmentDTO('zzz-outils', 'Zzz Outils', 'file://images/zzz.jpg', null),
            $this->findBySlug($result, 'zzz-outils'),
        );
    }

    public function testTheIconIsReadWhenThereIsOneAndTheKeyIsAlwaysThere(): void
    {
        // Given : un rayon avec une icône, un sans
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('aaa-surgele')->withName('Surgelé')->withImage('file://images/s.jpg')->withIcon(DepartmentIcon::SNOWFLAKE)->build());
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('bbb-divers')->withName('Divers')->withImage('file://images/d.jpg')->build());

        // When
        $list = $this->departmentLoader->list();

        // Then
        $this->assertEquals(new DepartmentDTO('aaa-surgele', 'Surgelé', 'file://images/s.jpg', 'snowflake'), $this->findBySlug($list, 'aaa-surgele'));
        $this->assertEquals(new DepartmentDTO('bbb-divers', 'Divers', 'file://images/d.jpg', null), $this->findBySlug($list, 'bbb-divers'));
        $this->assertSame('snowflake', $this->departmentLoader->get('aaa-surgele')->icon);
        $this->assertNull($this->departmentLoader->get('bbb-divers')->icon);
    }

    public function testAnIconThatThisVersionDoesNotKnowIsReadAsNoIconWithoutFailing(): void
    {
        // Given : une valeur écrite par une version plus récente
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build());
        $this->entityManager->getConnection()->executeStatement("UPDATE department SET icon = 'bag' WHERE slug = 'zzz-outils'");
        $this->entityManager->clear();

        // When / Then
        $this->assertNull($this->departmentLoader->get('zzz-outils')->icon);
        $this->assertNull($this->findBySlug($this->departmentLoader->list(), 'zzz-outils')->icon);
    }

    public function testGetReturnsTheDepartment(): void
    {
        // Given
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build());

        // When
        $result = $this->departmentLoader->get('zzz-outils');

        // Then
        $this->assertEquals(new DepartmentDTO('zzz-outils', 'Zzz Outils', 'file://images/zzz.jpg', null), $result);
    }

    public function testGetFailsForAnUnknownDepartment(): void
    {
        // Then
        $this->expectExceptionObject(DepartmentException::notFound());

        // When
        $this->departmentLoader->get('inconnu');
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
