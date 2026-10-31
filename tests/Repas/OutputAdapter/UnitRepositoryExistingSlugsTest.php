<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class UnitRepositoryExistingSlugsTest extends DatabaseTestCase
{
    private UnitRepository $resolver;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = static::getContainer()->get(UnitRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testReturnsTheSlugsThatDesignateAnUnit(): void
    {
        // Given : la tranche et la cuillère à soupe existent, le gramme aussi mais n'est pas demandé
        foreach ([new UnitBuilder()->isSlice(), new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isGramme()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }

        // When
        $result = $this->resolver->existingSlugs(['tranche', 'cuillere-a-soupe']);

        // Then
        $this->assertEqualsCanonicalizing(['tranche', 'cuillere-a-soupe'], $result);
    }

    public function testAnUnknownSlugIsAbsentAndADuplicateIsHarmless(): void
    {
        // Given
        $this->persister->saveUnit(new UnitBuilder()->isSlice()->build());

        // When
        $result = $this->resolver->existingSlugs(['inconnue', 'tranche', 'tranche', 'Tranche']);

        // Then : la casse compte, un slug est en minuscules
        $this->assertSame(['tranche'], $result);
    }

    public function testAnEmptyListCostsNoQueryAndAnyListCostsOne(): void
    {
        // Given
        $this->persister->saveUnit(new UnitBuilder()->isSlice()->build());
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $empty = $this->resolver->existingSlugs([]);

        // Then
        $this->assertSame([], $empty);
        $this->assertSame([], $counter->byTable());

        // When
        $this->resolver->existingSlugs(['tranche', 'gramme', 'cuillere-a-soupe']);

        // Then
        $this->assertSame(['SELECT unit' => 1], $counter->byTable());
    }
}
