<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

class ConversionPostgreSQLLoaderTest extends DatabaseTestCase
{
    private ConversionLoader $conversionLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversionLoader = static::getContainer()->get(ConversionLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testListReturnsEveryConversionWithItsIngredientCreatorAndUnits(): void
    {
        // Given
        $creator = new UserBuilder()->withEmail('createur@example.com')->build();
        $this->persister->saveUser($creator);
        $ingredient = new IngredientBuilder()->withName('zzz beurre')->withCreator($creator, true)->build();
        $conversion = new ConversionBuilder()->withIngredient($ingredient)->withStartUnit(new UnitBuilder()->isBlock()->build())
            ->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(250)->build();
        $this->persister->saveConversion($conversion);

        // When
        $result = $this->conversionLoader->list();

        // Then
        $this->assertEquals(
            [new ConversionDTO($conversion->getId(), $ingredient->getSlug(), 'zzz beurre', 'createur@example.com', 'plaquette', 'plaquette', 'gramme', 'gramme', 250.0)],
            $result,
        );
    }

    public function testListShowsNoCreatorForACommonIngredientNorForACommonConversion(): void
    {
        // Given
        $common = new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(1000)->build();
        $commonIngredient = new IngredientBuilder()->isEgg()->build();
        $forCommonIngredient = new ConversionBuilder()->withIngredient($commonIngredient)->withStartUnit(new UnitBuilder()->isUnite()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(60)->build();
        $this->persister->saveConversion($common);
        $this->persister->saveConversion($forCommonIngredient);

        // When
        $result = $this->conversionLoader->list();

        // Then
        $byId = array_column(array_map(static fn(ConversionDTO $c): array => [$c->id, $c], $result), 1, 0);
        $this->assertSame([null, null, null], [$byId[$common->getId()]->ingredientSlug, $byId[$common->getId()]->ingredientName, $byId[$common->getId()]->creatorEmail]);
        $this->assertSame(['oeuf', null], [$byId[$forCommonIngredient->getId()]->ingredientSlug, $byId[$forCommonIngredient->getId()]->creatorEmail]);
    }

    public function testListIsSortedByIngredientThenByStartUnitWithCommonConversionsLast(): void
    {
        // Given
        $egg = new IngredientBuilder()->isEgg()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $conversions = [
            'common' => new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->build(),
            'pasta' => new ConversionBuilder()->withIngredient($pasta)->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->build(),
            'egg-unite' => new ConversionBuilder()->withIngredient($egg)->withStartUnit(new UnitBuilder()->isUnite()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->build(),
            'egg-box' => new ConversionBuilder()->withIngredient($egg)->withStartUnit(new UnitBuilder()->isBox()->build())->withEndUnit(new UnitBuilder()->isUnite()->build())->build(),
        ];
        foreach ($conversions as $conversion) {
            $this->persister->saveConversion($conversion);
        }

        // When
        $result = $this->conversionLoader->list();

        // Then : œuf (boîte puis unité), pâtes, puis la conversion commune
        $this->assertSame(
            array_map(static fn(Conversion $c): string => $c->getId(), [$conversions['egg-box'], $conversions['egg-unite'], $conversions['pasta'], $conversions['common']]),
            array_map(static fn(ConversionDTO $c): string => $c->id, $result),
        );
    }

    public function testListIsEmptyWithoutConversion(): void
    {
        // When
        $result = $this->conversionLoader->list();

        // Then
        $this->assertSame([], $result);
    }

    public function testGetReturnsTheConversionWithSlugsForTheForm(): void
    {
        // Given
        $conversion = new ConversionBuilder()->isBlockToGrammeForButter()->build();
        $this->persister->saveConversion($conversion);

        // When
        $result = $this->conversionLoader->get($conversion->getId());

        // Then
        $this->assertEquals(new ConversionDTO($conversion->getId(), 'beurre', 'beurre', null, 'plaquette', 'plaquette', 'gramme', 'gramme', 250.0), $result);
    }

    public function testGetFailsForAnUnknownConversion(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::notFound('inconnue'));

        // When
        $this->conversionLoader->get('inconnue');
    }
}
