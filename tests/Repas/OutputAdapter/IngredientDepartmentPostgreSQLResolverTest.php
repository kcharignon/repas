<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Shared\Application\Interface\IngredientDepartmentResolver;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class IngredientDepartmentPostgreSQLResolverTest extends DatabaseTestCase
{
    private IngredientDepartmentResolver $resolver;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = static::getContainer()->get(IngredientDepartmentResolver::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testReturnsTheDepartmentOfEachKnownIngredient(): void
    {
        // Given : le lait et l'œuf dans « divers », la farine en boulangerie, le pain n'est pas demandé
        foreach ([new IngredientBuilder()->isMilk(), new IngredientBuilder()->isEgg(), new IngredientBuilder()->isFlour(), new IngredientBuilder()->isBread()] as $ingredient) {
            $this->persister->saveIngredient($ingredient->build());
        }

        // When
        $result = $this->resolver->departmentSlugsByIngredient(['lait', 'oeuf', 'farine']);

        // Then
        $this->assertEqualsCanonicalizing(['lait' => 'divers', 'oeuf' => 'divers', 'farine' => 'boulangerie'], $result);
    }

    public function testAnUnknownIngredientIsAbsentAndADuplicateSlugIsHarmless(): void
    {
        // Given
        $this->persister->saveIngredient(new IngredientBuilder()->isMilk()->withDepartment(new DepartmentBuilder()->isConserve())->build());

        // When
        $result = $this->resolver->departmentSlugsByIngredient(['inconnu', 'lait', 'lait']);

        // Then
        $this->assertSame(['lait' => 'conserve'], $result);
    }

    public function testAnEmptyListCostsNoQueryAndAnyListCostsOne(): void
    {
        // Given
        foreach ([new IngredientBuilder()->isMilk(), new IngredientBuilder()->isEgg(), new IngredientBuilder()->isFlour()] as $ingredient) {
            $this->persister->saveIngredient($ingredient->build());
        }
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $empty = $this->resolver->departmentSlugsByIngredient([]);

        // Then
        $this->assertSame([], $empty);
        $this->assertSame([], $counter->byTable());

        // When
        $this->resolver->departmentSlugsByIngredient(['lait', 'oeuf', 'farine']);

        // Then
        $this->assertSame(['SELECT ingredient' => 1], $counter->byTable());
    }
}
