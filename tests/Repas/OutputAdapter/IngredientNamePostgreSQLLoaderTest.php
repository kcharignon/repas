<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Shared\Application\Interface\IngredientNameLoader;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;

class IngredientNamePostgreSQLLoaderTest extends DatabaseTestCase
{
    private const string USER = IngredientBuilder::DEFAULT_CREATOR_ID;

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
        $result = $this->loader->namesBySlug(self::USER, ['oeuf', 'lait']);

        // Then
        $this->assertEqualsCanonicalizing(['oeuf' => 'œuf', 'lait' => 'lait'], $result);
        $this->assertSame('œuf', $result['oeuf']);
    }

    public function testNamesBySlugIgnoresUnknownSlugs(): void
    {
        // Given
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());

        // When
        $result = $this->loader->namesBySlug(self::USER, ['inconnu', 'oeuf']);

        // Then
        $this->assertSame(['oeuf' => 'œuf'], $result);
    }

    public function testNamesBySlugOnlyReadsTheIngredientsOfTheUser(): void
    {
        // Given : un ingrédient du demandeur, un d'un autre compte
        $other = new UserBuilder()->withId('autre-utilisateur')->build();
        $this->persister->saveIngredient(new IngredientBuilder()->isEgg()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isMilk()->withCreator($other)->build());

        // When
        $mine = $this->loader->namesBySlug(self::USER, ['oeuf', 'lait']);
        $theirs = $this->loader->namesBySlug('autre-utilisateur', ['oeuf', 'lait']);
        $nobody = $this->loader->namesBySlug('troisieme-utilisateur', ['oeuf', 'lait']);

        // Then : chacun ne lit que le nom de ses ingrédients
        $this->assertSame(['oeuf' => 'œuf'], $mine);
        $this->assertSame(['lait' => 'lait'], $theirs);
        $this->assertSame([], $nobody);
    }

    public function testNamesBySlugAcceptsAnEmptyListWithoutQuery(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->loader->namesBySlug(self::USER, []);

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
        $this->loader->namesBySlug(self::USER, ['oeuf', 'lait', 'oeuf']);

        // Then
        $this->assertSame(['SELECT ingredient' => 1], $counter->byTable());
    }
}
