<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\UnitLoader;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Shared\Domain\Model\Dimension;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class UnitPostgreSQLLoaderTest extends DatabaseTestCase
{
    private UnitRepository $unitRepository;
    private UnitLoader $unitLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unitRepository = static::getContainer()->get(UnitRepository::class);
        $this->unitLoader = static::getContainer()->get(UnitLoader::class);
    }

    public function testListReturnsNothingWhenThereIsNone(): void
    {
        // When
        $result = $this->unitLoader->list();

        // Then
        $this->assertSame([], $result);
    }

    public function testListReturnsDTOsOfCreatedUnits(): void
    {
        // Given
        $this->unitRepository->save(
            new UnitBuilder()->withSlug('zzz-pincee')->withName('Zzz pincée')->withSymbol('zz')->build()
        );

        // When
        $result = $this->unitLoader->list();

        // Then
        $this->assertContainsOnlyInstancesOf(UnitDTO::class, $result);
        $this->assertEquals(
            new UnitDTO('zzz-pincee', 'Zzz pincée', 'zz', null, null),
            $this->findBySlug($result, 'zzz-pincee'),
        );
    }

    public function testListKeepsAnEmptySymbol(): void
    {
        // Given
        $this->unitRepository->save(new UnitBuilder()->withSlug('zzz-sans-symbole')->withName('Zzz sans symbole')->withSymbol('')->build());

        // When
        $result = $this->unitLoader->list();

        // Then
        $this->assertEquals(
            new UnitDTO('zzz-sans-symbole', 'Zzz sans symbole', '', null, null),
            $this->findBySlug($result, 'zzz-sans-symbole'),
        );
    }

    public function testGetReturnsTheUnit(): void
    {
        // Given
        $this->unitRepository->save(new UnitBuilder()->withSlug('zzz-pincee')->withName('Zzz pincée')->withSymbol('zz')->build());

        // When
        $result = $this->unitLoader->get('zzz-pincee');

        // Then
        $this->assertEquals(new UnitDTO('zzz-pincee', 'Zzz pincée', 'zz', null, null), $result);
    }

    public function testGetAndListReturnTheDimensionAndTheBaseFactor(): void
    {
        // Given
        $this->unitRepository->save(new UnitBuilder()->isKilo()->withSlug('zzz-kilo')->withName('Zzz kilo')->build());
        $this->unitRepository->save(new UnitBuilder()->withSlug('zzz-paquet')->withName('Zzz paquet')->withMeasure(Dimension::PACKAGE, 1.0)->build());
        $this->unitRepository->save(new UnitBuilder()->withSlug('zzz-cuillere')->withName('Zzz cuillère')->build());

        // When
        $listed = $this->unitLoader->list();

        // Then : valeurs lues en base, null conservé pour une unité sans dimension
        $this->assertEquals(['WEIGHT', 1000.0], [$this->findBySlug($listed, 'zzz-kilo')->dimension, $this->findBySlug($listed, 'zzz-kilo')->baseFactor]);
        $this->assertEquals(new UnitDTO('zzz-paquet', 'Zzz paquet', 'Z', 'PACKAGE', 1.0), $this->findBySlug($listed, 'zzz-paquet'));
        $this->assertEquals(new UnitDTO('zzz-cuillere', 'Zzz cuillère', 'Z', null, null), $this->unitLoader->get('zzz-cuillere'));
        $this->assertEquals(new UnitDTO('zzz-paquet', 'Zzz paquet', 'Z', 'PACKAGE', 1.0), $this->unitLoader->get('zzz-paquet'));
    }

    public function testGetFailsForAnUnknownUnit(): void
    {
        // Then
        $this->expectExceptionObject(UnitException::notFound('inconnue'));

        // When
        $this->unitLoader->get('inconnue');
    }

    public function testListIsOrderedBySlug(): void
    {
        // Given
        $this->unitRepository->save(new UnitBuilder()->withSlug('zzz-second')->withName('Zzz second')->build());
        $this->unitRepository->save(new UnitBuilder()->withSlug('zzz-first')->withName('Zzz first')->build());

        // When
        $result = $this->unitLoader->list();

        // Then
        $slugs = array_map(static fn(UnitDTO $unit): string => $unit->slug, $result);
        $this->assertLessThan(array_search('zzz-second', $slugs), array_search('zzz-first', $slugs));
    }

    /**
     * @param list<UnitDTO> $units
     */
    private function findBySlug(array $units, string $slug): ?UnitDTO
    {
        foreach ($units as $unit) {
            if ($unit->slug === $slug) {
                return $unit;
            }
        }

        return null;
    }
}
