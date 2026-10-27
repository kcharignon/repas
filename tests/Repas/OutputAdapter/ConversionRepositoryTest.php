<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;

/**
 * Chaque test crée ses unités, ingrédients et conversions (la base de test est vide au départ).
 */
class ConversionRepositoryTest extends DatabaseTestCase
{
    private ConversionRepository $conversionRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversionRepository = static::getContainer()->get(ConversionRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testCRUD(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $conversion = new ConversionBuilder()->build();
        $this->persister->saveConversionDependencies($conversion);

        // Act
        $this->conversionRepository->save($conversion);

        // Assert
        $eggConversions = $this->conversionRepository->findByIngredientOrCommon($egg);
        $actual = $eggConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        RepasAssert::assertConversion($conversion, $actual);

        // Arrange
        $milk = new IngredientBuilder()->isMilk()->build();
        $conversion->update(
            startUnit: new UnitBuilder()->isKilo()->build(),
            endUnit: new UnitBuilder()->isCentiliter()->build(),
            coefficient: 100,
            ingredient: $milk,
        );
        $this->persister->saveConversionDependencies($conversion);

        // Act
        $this->conversionRepository->save($conversion);

        // Assert
        $eggConversions = $this->conversionRepository->findByIngredientOrCommon($egg);
        $actual = $eggConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        $this->assertNull($actual);

        $milkConversions = $this->conversionRepository->findByIngredientOrCommon($milk);
        $actual = $milkConversions->find(fn(Conversion $c) =>  $c->isEqual($conversion));
        RepasAssert::assertConversion($conversion, $actual);
    }

    public function testFindAll(): void
    {
        // Arrange
        $forEgg = new ConversionBuilder()->isUnitToGrammeForEgg()->build();
        $common = new ConversionBuilder()->isKiloToGramme()->build();
        $this->persister->saveConversion($forEgg);
        $this->persister->saveConversion($common);

        // Act
        $conversions = $this->conversionRepository->findAll();

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Conversion::class), $conversions);
        $this->assertCount(2, $conversions);
        RepasAssert::assertConversion($forEgg, $conversions->find(fn(Conversion $c) => $c->isEqual($forEgg)));
        RepasAssert::assertConversion($common, $conversions->find(fn(Conversion $c) => $c->isEqual($common)));
    }

    public function testFindByIngredientAndStartUnitAndEndUnit(): void
    {
        // Arrange
        $expected = new ConversionBuilder()->isBlockToGrammeForButter()->build();
        $this->persister->saveConversion($expected);

        // Act
        $actual = $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit(
            new IngredientBuilder()->isButter()->build(),
            new UnitBuilder()->isBlock()->build(),
            new UnitBuilder()->isGramme()->build(),
        );

        // Assert
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testTheLookupOfAPairIsTheIngredientsOwnAndNeverAUniversalLine(): void
    {
        // Given : « kilo > gramme » universelle, et une ligne propre au beurre sur une autre paire
        $butter = new IngredientBuilder()->isButter()->build();
        $universal = new ConversionBuilder()->isKiloToGramme()->build();
        $own = new ConversionBuilder()->isBlockToGrammeForButter()->build();
        $this->persister->saveConversion($universal);
        $this->persister->saveConversion($own);

        // When / Then : la paire universelle n'est pas celle du beurre ; sa paire propre l'est
        $this->assertNull($this->conversionRepository->findByIngredientAndStartUnitAndEndUnit($butter, new UnitBuilder()->isKilo()->build(), new UnitBuilder()->isGramme()->build()));
        $this->assertNotNull($this->conversionRepository->findByIngredientAndStartUnitAndEndUnit($butter, new UnitBuilder()->isBlock()->build(), new UnitBuilder()->isGramme()->build()));
    }

    public function testFindOwnBetweenFindsTheOwnLinesOfThePairInBothDirectionsInIdOrder(): void
    {
        // Given : la farine a une ligne « kilo > gramme » (id tardif) et la même paire à l'envers « gramme > kilo » (id précoce), plus une autre paire ;
        // une ligne universelle relie aussi kilo et gramme ; le beurre a une ligne sur la même paire
        $flour = new IngredientBuilder()->isFlour()->build();
        $butter = new IngredientBuilder()->isButter()->build();
        $kilo = new UnitBuilder()->isKilo()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
        $natural = new ConversionBuilder()->withId('99999999-9999-4999-8999-999999999999')->withStartUnit($kilo)->withEndUnit($gramme)->withCoefficient(1000)->withIngredient($flour)->build();
        $reversed = new ConversionBuilder()->withId('00000000-0000-4000-8000-000000000001')->withStartUnit($gramme)->withEndUnit($kilo)->withCoefficient(0.001)->withIngredient($flour)->build();
        $other = new ConversionBuilder()->withId('55555555-5555-4555-8555-555555555555')->withStartUnit(new UnitBuilder()->isBox()->build())->withEndUnit($gramme)->withCoefficient(400)->withIngredient($flour)->build();
        $universal = new ConversionBuilder()->isKiloToGramme()->build();
        $butterLine = new ConversionBuilder()->withId('33333333-3333-4333-8333-333333333333')->withStartUnit($kilo)->withEndUnit($gramme)->withCoefficient(1000)->withIngredient($butter)->build();
        foreach ([$natural, $reversed, $other, $universal, $butterLine] as $conversion) {
            $this->persister->saveConversion($conversion);
        }

        // When : dans un sens comme dans l'autre de la question
        $forward = $this->conversionRepository->findOwnBetween($flour, $kilo, $gramme);
        $backward = $this->conversionRepository->findOwnBetween($flour, $gramme, $kilo);

        // Then : les deux lignes propres de la paire, la plus ancienne par identifiant d'abord, jamais l'universelle, l'autre paire ni le beurre
        $ids = static fn($found): array => array_values(array_map(static fn($conversion): string => $conversion->getId(), $found->toArray()));
        $this->assertSame([$reversed->getId(), $natural->getId()], $ids($forward));
        $this->assertSame([$reversed->getId(), $natural->getId()], $ids($backward));
        $this->assertCount(0, $this->conversionRepository->findOwnBetween($flour, $kilo, new UnitBuilder()->isBox()->build()));
    }

    public function testFindById(): void
    {
        // Arrange
        $expected = new ConversionBuilder()->isBlockToGrammeForButter()->build();
        $this->persister->saveConversion($expected);

        // Act
        $actual = $this->conversionRepository->findById($expected->getId());

        // Assert
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testFindByIngredient(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isFlour()->build();
        $conversion1 = new ConversionBuilder()
            ->withStartUnit(new UnitBuilder()->isKilo()->build())
            ->withEndUnit(new UnitBuilder()->isGramme()->build())
            ->withCoefficient(1000)
            ->withIngredient($ingredient)
            ->build();

        $conversion2 = new ConversionBuilder()
            ->withStartUnit(new UnitBuilder()->isBowl()->build())
            ->withEndUnit(new UnitBuilder()->isGramme()->build())
            ->withCoefficient(120)
            ->withIngredient($ingredient)
            ->build();

        $this->persister->saveConversion($conversion1);
        $this->persister->saveConversion($conversion2);

        // Act
        $conversions = $this->conversionRepository->findByIngredient($ingredient);

        // Assert
        $this->assertCount(2, $conversions);
        $actual1 = $conversions->find(fn(Conversion $c) =>  $c->isEqual($conversion1));
        $actual2 = $conversions->find(fn(Conversion $c) =>  $c->isEqual($conversion2));

        RepasAssert::assertConversion($conversion1, $actual1);
        RepasAssert::assertConversion($conversion2, $actual2);
    }

    public function testTheOwnConversionsOfACommonCopiedToAnAdoptionAreTheCopysAndTheCommonOnesStayShared(): void
    {
        // Arrange : un ingrédient du catalogue avec deux conversions propres, plus une conversion générale (kilo -> gramme) ; l'utilisateur en reçoit une copie
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $common = new IngredientBuilder()->isButter()->build();
        $block = new ConversionBuilder()->isBlockToGrammeForButter()->build();
        $spoon = new ConversionBuilder()->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(15)->withIngredient($common)->build();
        $kilo = new ConversionBuilder()->isKiloToGramme()->build();
        foreach ([$block, $spoon, $kilo] as $conversion) {
            $this->persister->saveConversion($conversion);
        }
        $adoption = Ingredient::copyFromOriginal($common, $user->getId(), 'beurre' . $user->getId());
        $this->persister->saveIngredientDependencies($adoption);
        static::getContainer()->get(IngredientRepository::class)->save($adoption);

        // Act : ce que font la copie d'une recette et l'amorçage d'un compte
        $copies = [];
        foreach ($this->conversionRepository->findByIngredient($common) as $conversion) {
            $copy = Conversion::copyFromOriginal(UuidGenerator::new(), $conversion, $adoption);
            $this->conversionRepository->save($copy);
            $copies[$copy->getId()] = $copy;
        }
        $this->entityManager->clear();

        // Assert : la copie a SES conversions, avec de nouveaux identifiants
        $ownOfCopy = $this->conversionRepository->findByIngredient($adoption);
        $this->assertCount(2, $ownOfCopy);
        foreach ($ownOfCopy as $actual) {
            $this->assertArrayHasKey($actual->getId(), $copies);
            $this->assertNotContains($actual->getId(), [$block->getId(), $spoon->getId()]);
            RepasAssert::assertConversion($copies[$actual->getId()], $actual);
        }
        $this->assertEqualsCanonicalizing(
            [['plaquette', 'gramme', 250.0], ['cuillere-a-soupe', 'gramme', 15.0]],
            array_map(fn(Conversion $c) => [$c->getStartUnit()->getSlug(), $c->getEndUnit()->getSlug(), $c->getCoefficient()], array_values($ownOfCopy->toArray())),
        );
        // Le commun garde les siennes, et la conversion commune n'a pas été copiée : le graphe de la copie est celui du commun
        $this->assertEqualsCanonicalizing([$block->getId(), $spoon->getId()], array_map(fn(Conversion $c) => $c->getId(), array_values($this->conversionRepository->findByIngredient($common)->toArray())));
        $this->assertCount(1, $this->conversionRepository->findAll()->filter(fn(Conversion $c) => $c->getIngredient() === null));
        $withCommon = $this->conversionRepository->findByIngredientOrCommon($adoption);
        $this->assertCount(3, $withCommon);
        $this->assertNotNull($withCommon->find(fn(Conversion $c) => $c->isEqual($kilo)));
    }

    public function testDeleteByIngredient(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isSugar()->build();
        $conversion1 = new ConversionBuilder()
            ->withStartUnit(new UnitBuilder()->isKilo()->build())
            ->withEndUnit(new UnitBuilder()->isGramme()->build())
            ->withCoefficient(1000)
            ->withIngredient($ingredient)
            ->build();

        $conversion2 = new ConversionBuilder()
            ->withStartUnit(new UnitBuilder()->isCoffeeSpoon()->build())
            ->withEndUnit(new UnitBuilder()->isGramme()->build())
            ->withCoefficient(4)
            ->withIngredient($ingredient)
            ->build();

        $this->persister->saveConversion($conversion1);
        $this->persister->saveConversion($conversion2);

        // Act
        $this->conversionRepository->deleteByIngredient($ingredient);

        // Assert
        $conversions = $this->conversionRepository->findByIngredient($ingredient);
        $this->assertCount(0, $conversions);
    }

    public function testDeleteRemovesOnlyThatConversion(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isSugar()->build();
        $spoon = new ConversionBuilder()->withStartUnit(new UnitBuilder()->isCoffeeSpoon()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(4)->withIngredient($ingredient)->build();
        $glass = new ConversionBuilder()->withStartUnit(new UnitBuilder()->isGlass()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(150)->withIngredient($ingredient)->build();
        $universal = new ConversionBuilder()->isKiloToGramme()->build();
        foreach ([$spoon, $glass, $universal] as $conversion) {
            $this->persister->saveConversion($conversion);
        }

        // Act
        $this->conversionRepository->delete($spoon);

        // Assert : la conversion retirée a disparu, ni l'autre conversion propre ni l'universelle n'ont bougé
        $remaining = array_map(static fn(Conversion $c): string => $c->getId(), $this->conversionRepository->findByIngredientOrCommon($ingredient)->toArray());
        $this->assertEqualsCanonicalizing([$glass->getId(), $universal->getId()], $remaining);
    }

    public function testDeleteOfAConversionNotInTheBaseDoesNothing(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isSugar()->build();
        $kept = new ConversionBuilder()->withIngredient($ingredient)->build();
        $this->persister->saveConversion($kept);

        // Act
        $this->conversionRepository->delete(new ConversionBuilder()->withId(UuidGenerator::new())->withIngredient($ingredient)->build());

        // Assert
        $this->assertCount(1, $this->conversionRepository->findByIngredient($ingredient));
    }

    public function testTheIngredientsOwnConversionsComeBeforeTheUniversalOnes(): void
    {
        // Arrange : les identifiants rangent la universelle AVANT les propres, pour prouver que l'ordre ne vient pas d'eux
        $ingredient = new IngredientBuilder()->isSugar()->build();
        $universal = new ConversionBuilder()->isKiloToGramme()->withId('0000-universal')->build();
        $first = new ConversionBuilder()->withStartUnit(new UnitBuilder()->isCoffeeSpoon()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(4)->withIngredient($ingredient)->withId('zzzz-own-1')->build();
        $second = new ConversionBuilder()->withStartUnit(new UnitBuilder()->isGlass()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(150)->withIngredient($ingredient)->withId('zzzz-own-2')->build();
        foreach ([$universal, $second, $first] as $conversion) {
            $this->persister->saveConversion($conversion);
        }

        // Act
        $result = $this->conversionRepository->findByIngredientOrCommon($ingredient);

        // Assert : les siennes (par identifiant), puis la universelle
        $this->assertSame(['zzzz-own-1', 'zzzz-own-2', '0000-universal'], array_map(static fn(Conversion $c): string => $c->getId(), $result->toArray()));
    }

    public function testIngredientHasAtLeastCountsOnlyTheOwnConversionsOfTheIngredient(): void
    {
        // Arrange : trois conversions propres à la farine, une au sucre, plus une universelle
        $flour = new IngredientBuilder()->isFlour()->build();
        $sugar = new IngredientBuilder()->isSugar()->build();
        foreach ([[new UnitBuilder()->isKilo(), 1000], [new UnitBuilder()->isBowl(), 120], [new UnitBuilder()->isGlass(), 130]] as [$unit, $coefficient]) {
            $this->persister->saveConversion(new ConversionBuilder()->withStartUnit($unit)->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient($coefficient)->withIngredient($flour)->build());
        }
        $this->persister->saveConversion(new ConversionBuilder()->withStartUnit(new UnitBuilder()->isCoffeeSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(4)->withIngredient($sugar)->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());

        // Act / Assert : exactement à la limite, pour chacun
        $this->assertSame([true, true, false], [
            $this->conversionRepository->ingredientHasAtLeast($flour, 2),
            $this->conversionRepository->ingredientHasAtLeast($flour, 3),
            $this->conversionRepository->ingredientHasAtLeast($flour, 4),
        ]);
        $this->assertSame([true, false], [
            $this->conversionRepository->ingredientHasAtLeast($sugar, 1),
            $this->conversionRepository->ingredientHasAtLeast($sugar, 2),
        ]);
        $this->assertFalse($this->conversionRepository->ingredientHasAtLeast(new IngredientBuilder()->isButter()->build(), 1));
    }
}
