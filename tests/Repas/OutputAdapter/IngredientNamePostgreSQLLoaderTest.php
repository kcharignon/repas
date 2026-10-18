<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Shared\Application\Interface\IngredientNameLoader;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class IngredientNamePostgreSQLLoaderTest extends DatabaseTestCase
{
    private IngredientNameLoader $loader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(IngredientNameLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testNamesBySlugReturnsTheNameOfEachKnownSlug(): void
    {
        // Given
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isMilk()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->build());

        // When
        $result = $this->loader->namesBySlug(['oeuf', 'lait']);

        // Then
        $this->assertEqualsCanonicalizing(['oeuf' => 'œuf', 'lait' => 'lait'], $result);
        $this->assertSame('œuf', $result['oeuf']);
    }

    public function testNamesBySlugIgnoresUnknownSlugs(): void
    {
        // Given
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());

        // When
        $result = $this->loader->namesBySlug(['inconnu', 'oeuf']);

        // Then
        $this->assertSame(['oeuf' => 'œuf'], $result);
    }

    public function testNamesBySlugAcceptsAnEmptyListWithoutQuery(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->loader->namesBySlug([]);

        // Then
        $this->assertSame([], $result);
        $this->assertSame([], $counter->byTable());
    }

    public function testNamesBySlugCostsOneQuery(): void
    {
        // Given
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isMilk()->build());
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader->namesBySlug(['oeuf', 'lait', 'oeuf']);

        // Then
        $this->assertSame(['SELECT ingredient' => 1], $counter->byTable());
    }
}
