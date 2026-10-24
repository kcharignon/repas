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
use Repas\User\Domain\Model\User;

/**
 * L'écran des conversions de l'administrateur (ADR 0028, amendement du 2026-10-20, D4) : les conversions générales et celles de SES
 * ingrédients, jamais celles des ingrédients d'un autre compte, même pour un administrateur.
 */
class ConversionPostgreSQLLoaderTest extends DatabaseTestCase
{
    private ConversionLoader $conversionLoader;
    private DataPersister $persister;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversionLoader = static::getContainer()->get(ConversionLoader::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->admin = new UserBuilder()->withEmail('admin@example.com')->isAdmin()->build();
        $this->persister->saveUser($this->admin);
    }

    public function testListReturnsTheConversionsOfTheViewersIngredientsWithTheirOwnerAndUnits(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->withName('zzz beurre')->withCreator($this->admin, true)->build();
        $conversion = new ConversionBuilder()->withIngredient($ingredient)->withStartUnit(new UnitBuilder()->isBlock()->build())
            ->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(250)->build();
        $this->persister->saveConversion($conversion);

        // When
        $result = $this->conversionLoader->list($this->admin->getId());

        // Then
        $this->assertEquals(
            [new ConversionDTO($conversion->getId(), $ingredient->getSlug(), 'zzz beurre', 'admin@example.com', 'plaquette', 'plaquette', 'gramme', 'gramme', 250.0)],
            $result,
        );
    }

    public function testListNeverReturnsTheConversionsOfTheIngredientsOfAnotherAccount(): void
    {
        // Given : le propriétaire du catalogue (un autre administrateur) et un utilisateur ont des conversions propres ; l'administrateur lecteur
        // a la sienne, et il existe une conversion générale
        $other = new UserBuilder()->isAdmin()->build();
        $user = new UserBuilder()->build();
        $this->persister->saveUser($other);
        $this->persister->saveUser($user);
        $mine = $this->saved($this->admin, 'mon beurre');
        $theirs = $this->saved($other, 'son beurre');
        $users = $this->saved($user, 'beurre perso');
        $general = new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(1000)->build();
        $this->persister->saveConversion($general);

        // When
        $result = $this->conversionLoader->list($this->admin->getId());

        // Then : la sienne et la générale, ni celle de l'autre administrateur ni celle de l'utilisateur
        $ids = array_map(static fn(ConversionDTO $c): string => $c->id, $result);
        sort($ids);
        $expected = [$mine->getId(), $general->getId()];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($theirs->getId(), $ids);
        $this->assertNotContains($users->getId(), $ids);
    }

    public function testListShowsNoOwnerForAGeneralConversion(): void
    {
        // Given
        $general = new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(1000)->build();
        $this->persister->saveConversion($general);

        // When
        $result = $this->conversionLoader->list($this->admin->getId());

        // Then
        $this->assertSame([null, null, null], [$result[0]->ingredientSlug, $result[0]->ingredientName, $result[0]->creatorEmail]);
    }

    public function testListIsSortedByIngredientThenByStartUnitWithGeneralConversionsLast(): void
    {
        // Given
        $egg = new IngredientBuilder()->isEgg()->withCreator($this->admin)->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($this->admin)->build();
        $conversions = [
            'general' => new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->build(),
            'pasta' => new ConversionBuilder()->withIngredient($pasta)->withStartUnit(new UnitBuilder()->isKilo()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->build(),
            'egg-unite' => new ConversionBuilder()->withIngredient($egg)->withStartUnit(new UnitBuilder()->isUnite()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->build(),
            'egg-box' => new ConversionBuilder()->withIngredient($egg)->withStartUnit(new UnitBuilder()->isBox()->build())->withEndUnit(new UnitBuilder()->isUnite()->build())->build(),
        ];
        foreach ($conversions as $conversion) {
            $this->persister->saveConversion($conversion);
        }

        // When
        $result = $this->conversionLoader->list($this->admin->getId());

        // Then : œuf (boîte puis unité), pâtes, puis la conversion générale
        $this->assertSame(
            array_map(static fn(Conversion $c): string => $c->getId(), [$conversions['egg-box'], $conversions['egg-unite'], $conversions['pasta'], $conversions['general']]),
            array_map(static fn(ConversionDTO $c): string => $c->id, $result),
        );
    }

    public function testListIsEmptyWithoutConversion(): void
    {
        // When
        $result = $this->conversionLoader->list($this->admin->getId());

        // Then
        $this->assertSame([], $result);
    }

    public function testGetReturnsTheConversionOfTheViewersIngredientWithSlugsForTheForm(): void
    {
        // Given
        $conversion = $this->saved($this->admin, 'beurre');

        // When
        $result = $this->conversionLoader->get($conversion->getId(), $this->admin->getId());

        // Then
        $this->assertEquals(new ConversionDTO($conversion->getId(), $conversion->getIngredient()->getSlug(), 'beurre', 'admin@example.com', 'plaquette', 'plaquette', 'gramme', 'gramme', 250.0), $result);
    }

    public function testGetReturnsAGeneralConversionToAnyAdministrator(): void
    {
        // Given
        $general = new ConversionBuilder()->isKiloToGramme()->build();
        $this->persister->saveConversion($general);

        // When
        $result = $this->conversionLoader->get($general->getId(), $this->admin->getId());

        // Then
        $this->assertSame([null, 'kilo', 'gramme'], [$result->ingredientSlug, $result->startUnitSlug, $result->endUnitSlug]);
    }

    public function testGetDoesNotKnowTheConversionOfTheIngredientOfAnotherAccount(): void
    {
        // Given
        $other = new UserBuilder()->isAdmin()->build();
        $this->persister->saveUser($other);
        $theirs = $this->saved($other, 'son beurre');

        // Then : inconnue, un administrateur compris
        $this->expectExceptionObject(ConversionException::notFound($theirs->getId()));

        // When
        $this->conversionLoader->get($theirs->getId(), $this->admin->getId());
    }

    public function testGetFailsForAnUnknownConversion(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::notFound('inconnue'));

        // When
        $this->conversionLoader->get('inconnue', $this->admin->getId());
    }

    private function saved(User $creator, string $name): Conversion
    {
        $ingredient = new IngredientBuilder()->withName($name)->withCreator($creator, true)->build();
        $conversion = new ConversionBuilder()->withIngredient($ingredient)->withStartUnit(new UnitBuilder()->isBlock()->build())
            ->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(250)->build();
        $this->persister->saveConversion($conversion);

        return $conversion;
    }
}
