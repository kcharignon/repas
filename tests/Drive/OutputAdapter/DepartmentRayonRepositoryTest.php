<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Repas\Drive\Domain\Interface\DepartmentRayonRepository;
use Repas\Drive\Domain\Model\DepartmentRayonChoice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class DepartmentRayonRepositoryTest extends DatabaseTestCase
{
    private DepartmentRayonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(DepartmentRayonRepository::class);
    }

    public function testChoicesAreCountedPerDepartmentAndRayonAndAccumulate(): void
    {
        // When : trois choix dont deux identiques dans une même liste, puis deux autres plus tard
        $this->repository->recordChoices(Retailer::LECLERC, [
            new DepartmentRayonChoice('boulangerie', 4001),
            new DepartmentRayonChoice('boulangerie', 4001),
            new DepartmentRayonChoice('epicerie', 4001),
        ]);
        $this->repository->recordChoices(Retailer::LECLERC, [new DepartmentRayonChoice('boulangerie', 4001), new DepartmentRayonChoice('boulangerie', 4002)]);

        // Then : les doublons de la liste s'additionnent, les appels successifs aussi
        $this->assertSame(
            [['boulangerie', 4001, 3], ['boulangerie', 4002, 1], ['epicerie', 4001, 1]],
            $this->rows(),
        );
    }

    public function testAnEmptyListCostsNoQueryAndAnyListCostsOne(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->recordChoices(Retailer::LECLERC, []);

        // Then
        $this->assertSame([], $counter->byTable());

        // When : 60 choix de 30 couples différents
        $this->repository->recordChoices(Retailer::LECLERC, array_map(
            static fn(int $i): DepartmentRayonChoice => new DepartmentRayonChoice('rayon-' . intdiv($i, 3), 4000 + ($i % 3)),
            range(0, 59),
        ));

        // Then
        $this->assertSame(['INSERT drive_department_rayon' => 1], $counter->byTable());
        $this->assertCount(60, $this->rows());
    }

    public function testNothingDesignatesAUserOrAStoreSoThereIsNothingToPurgeWithAnAccount(): void
    {
        // When
        $columns = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'drive_department_rayon' ORDER BY column_name",
        );

        // Then : des faits de catalogue agrégés par enseigne (comme `drive_product`), pas une donnée de personne (ADR 0021)
        $this->assertSame(['chosen_count', 'department_slug', 'rayon_id', 'retailer'], $columns);
    }

    /**
     * @return list<array{string, int, int}> rayon de Repas, rayon de l'enseigne, nombre de choix
     */
    private function rows(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT department_slug, rayon_id, chosen_count FROM drive_department_rayon ORDER BY department_slug, rayon_id');

        return array_map(static fn(array $row): array => [$row['department_slug'], (int) $row['rayon_id'], (int) $row['chosen_count']], $rows);
    }
}
