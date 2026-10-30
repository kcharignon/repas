<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionDTO;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
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
            [new ConversionDTO($conversion->getId(), $ingredient->getSlug(), 'zzz beurre', 'admin@example.com', 'plaquette', 'plaquette', 'gramme', 'gramme', 250.0, 'conserve', 'conserve')],
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
        $this->assertEquals(new ConversionDTO($conversion->getId(), $conversion->getIngredient()->getSlug(), 'beurre', 'admin@example.com', 'plaquette', 'plaquette', 'gramme', 'gramme', 250.0, 'conserve', 'conserve'), $result);
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

    public function testListOfIngredientReturnsItsOwnConversionsAndTheUniversalOnesThatTouchItsUnits(): void
    {
        // Given : la farine (recettes en cuillère à soupe, achat au kilo) a deux conversions propres ; il existe trois conversions universelles dont une seule touche ses unités
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->admin, true)->withDefaultCookingUnit(new UnitBuilder()->isSoupSpoon())->build();
        $spoon = new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build();
        $cup = new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->withName('tasse'))->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(125)->build();
        $kilo = new ConversionBuilder()->isKiloToGramme()->build();
        $litre = new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isLiter())->withEndUnit(new UnitBuilder()->isMillilitre())->withCoefficient(1000)->build();
        foreach ([$spoon, $cup, $kilo, $litre] as $conversion) {
            $this->persister->saveConversion($conversion);
        }

        // When
        $result = $this->conversionLoader->listOfIngredient($flour->getSlug(), $this->admin->getId());

        // Then : les siennes triées par nom d'unité de départ (cuillère, tasse), l'universelle qui touche le gramme ou le kilo, pas le litre ; l'unité des recettes se convertit en l'unité d'achat
        $this->assertEquals(
            [
                new IngredientConversionDTO($spoon->getId(), 'cuillere-a-soupe', 'cuillère à soupe', 'gramme', 'gramme', 10.0),
                new IngredientConversionDTO($cup->getId(), 'tasse', 'tasse', 'gramme', 'gramme', 125.0),
            ],
            $result->member,
        );
        $this->assertEquals([new IngredientConversionDTO($kilo->getId(), 'kilo', 'kilo', 'gramme', 'gramme', 1000.0)], $result->common);
        $this->assertSame([2, true, 'cuillere-a-soupe', 'kilo'], [$result->totalItems, $result->convertible, $result->cookingUnitSlug, $result->purchaseUnitSlug]);
    }

    public function testListOfIngredientSaysWhenTheRecipeUnitDoesNotConvertToThePurchaseUnit(): void
    {
        // Given : aucune conversion ne relie la cuillère à soupe au kilo de cette farine (celle d'un autre ingrédient n'y change rien)
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->admin, true)->withDefaultCookingUnit(new UnitBuilder()->isSoupSpoon())->build();
        $other = new IngredientBuilder()->withName('sel')->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($flour);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($other)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());

        // When
        $result = $this->conversionLoader->listOfIngredient($flour->getSlug(), $this->admin->getId());

        // Then
        $this->assertSame([[], false, 0], [$result->member, $result->convertible, $result->totalItems]);
        // L'universelle touche le kilo (l'unité d'achat) : elle est montrée
        $this->assertCount(1, $result->common);
    }

    public function testListOfIngredientIsConvertibleWhenBothUnitsAreTheSame(): void
    {
        // Given
        $egg = new IngredientBuilder()->isEgg()->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($egg);

        // When
        $result = $this->conversionLoader->listOfIngredient($egg->getSlug(), $this->admin->getId());

        // Then
        $this->assertSame([[], [], 0, true], [$result->member, $result->common, $result->totalItems, $result->convertible]);
    }

    public function testListOfIngredientDoesNotKnowTheIngredientOfAnotherAccountAdministratorIncluded(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $theirs = new IngredientBuilder()->isFlour()->withCreator($user, true)->build();
        $this->persister->saveIngredient($theirs);

        // Then : inconnu, même pour un administrateur
        $this->expectExceptionObject(IngredientException::notFound($theirs->getSlug()));

        // When
        $this->conversionLoader->listOfIngredient($theirs->getSlug(), $this->admin->getId());
    }

    public function testListOfIngredientFailsForAnUnknownIngredient(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // When
        $this->conversionLoader->listOfIngredient('inconnu', $this->admin->getId());
    }

    public function testListOfIngredientReadsEverythingInTwoQueries(): void
    {
        // Given : un ingrédient avec plusieurs conversions
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->admin, true)->build();
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        foreach (['tasse', 'bol', 'verre'] as $name) {
            $this->persister->saveConversion(new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->withName($name))->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(100)->build());
        }
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->conversionLoader->listOfIngredient($flour->getSlug(), $this->admin->getId());

        // Then : l'ingrédient, puis ses conversions et les universelles : pas de requête par conversion
        $this->assertSame(['SELECT ingredient' => 1, 'SELECT conversion' => 1], $counter->byTable());
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
