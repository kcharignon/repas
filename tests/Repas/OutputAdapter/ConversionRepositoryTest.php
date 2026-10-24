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
}
