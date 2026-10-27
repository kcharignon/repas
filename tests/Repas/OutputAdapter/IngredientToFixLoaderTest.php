<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\IngredientToFixLoader;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientToFixDTO;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les ingrédients à régler d'un utilisateur sur la vraie base : seuls les siens, jugés par le même graphe de conversion que la planification
 * (une conversion propre, une universelle, plusieurs étapes), deux unités identiques jamais à régler, deux lectures quel que soit leur nombre.
 */
class IngredientToFixLoaderTest extends DatabaseTestCase
{
    private IngredientToFixLoader $loader;
    private DataPersister $persister;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(IngredientToFixLoader::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->user = $this->savedUser();
    }

    public function testAnIngredientWhoseRecipeUnitDoesNotConvertToItsPurchaseUnitIsToFix(): void
    {
        // Given : aucune conversion ne relie la cuillère à soupe au kilo
        $salt = $this->saved('sel', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo());

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then
        $this->assertSame(1, $result->totalItems);
        $this->assertEquals(
            [new IngredientToFixDTO($salt->getSlug(), 'sel', $salt->getDepartment()->getName(), 'cuillere-a-soupe', 'cuillère à soupe', 'kilo', 'kilo')],
            $result->member,
        );
    }

    public function testAnIngredientConvertedByAUniversalConversionIsNotToFix(): void
    {
        // Given : gramme (recettes) et kilo (achat), reliés par la conversion universelle kilo > gramme
        $this->saved('farine', new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then
        $this->assertSame([[], 0], [$result->member, $result->totalItems]);
    }

    public function testAnIngredientConvertedByItsOwnConversionIsNotToFix(): void
    {
        // Given : la plaquette (achat) vaut 250 grammes (recettes) pour le beurre
        $butter = $this->saved('beurre', new UnitBuilder()->isGramme(), new UnitBuilder()->isBlock());
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($butter)->withStartUnit(new UnitBuilder()->isBlock())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(250)->build());

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then
        $this->assertSame(0, $result->totalItems);
    }

    public function testAnIngredientConvertedInSeveralStepsIsNotToFix(): void
    {
        // Given : la cuillère à soupe vaut 10 grammes pour la farine (propre), le kilo vaut 1000 grammes (universelle) : deux étapes
        $flour = $this->saved('farine', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build());

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then
        $this->assertSame(0, $result->totalItems);
    }

    public function testTheConversionOfOneIngredientDoesNotServeAnother(): void
    {
        // Given : seule la farine sait convertir la cuillère à soupe en gramme ; le sel a les mêmes unités et aucune conversion
        $flour = $this->saved('farine', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isGramme());
        $salt = $this->saved('sel', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isGramme());
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build());

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then
        $this->assertSame([$salt->getSlug()], array_map(static fn(IngredientToFixDTO $i): string => $i->slug, $result->member));
    }

    public function testAnIngredientWithTheSameUnitTwiceIsNeverToFix(): void
    {
        // Given : la même unité pour les recettes et pour l'achat
        $this->saved('œuf', new UnitBuilder()->isUnite(), new UnitBuilder()->isUnite());

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then
        $this->assertSame([[], 0], [$result->member, $result->totalItems]);
    }

    public function testNeverListsTheIngredientsOfAnotherAccountAnAdministratorIncluded(): void
    {
        // Given : un autre compte et un administrateur ont chacun un ingrédient à régler, l'utilisateur n'a rien
        $other = $this->savedUser();
        $admin = new UserBuilder()->isAdmin()->build();
        $this->persister->saveUser($admin);
        $this->saved('sel de l\'autre', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo(), $other);
        $this->saved('sel de l\'administrateur', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo(), $admin);

        // When
        $mine = $this->loader->toFix($this->user->getId());
        $theirs = $this->loader->toFix($other->getId());
        $administrators = $this->loader->toFix($admin->getId());

        // Then : chacun ne voit que le sien
        $this->assertSame(0, $mine->totalItems);
        $this->assertSame(['sel de l\'autre'], array_map(static fn(IngredientToFixDTO $i): string => $i->name, $theirs->member));
        $this->assertSame(['sel de l\'administrateur'], array_map(static fn(IngredientToFixDTO $i): string => $i->name, $administrators->member));
    }

    public function testTheListIsSortedByNameThenBySlug(): void
    {
        // Given : saisis dans le désordre
        $this->saved('mmm sel', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo());
        $this->saved('aaa poivre', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo());
        $this->saved('zzz sucre', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo());

        // When
        $first = $this->loader->toFix($this->user->getId());
        $second = $this->loader->toFix($this->user->getId());

        // Then : le même ordre à chaque appel
        $this->assertSame(['aaa poivre', 'mmm sel', 'zzz sucre'], array_map(static fn(IngredientToFixDTO $i): string => $i->name, $first->member));
        $this->assertEquals($first, $second);
    }

    public function testTheListIsCappedAt200ButTotalItemsIsTheRealNumber(): void
    {
        // Given : un ingrédient à régler, cloné 204 fois en SQL (205 au total)
        $salt = $this->saved('zzz sel', new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo());
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO ingredient (slug, name, image, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, slugged_name, adopted_from, is_base)"
            . " SELECT i.slug || '-' || n, 'aaa ' || lpad(n::text, 4, '0'), i.image, i.department, i.default_cooking_unit, i.default_purchase_unit, i.creator, i.compatible_units, i.slugged_name || '-' || n, NULL, FALSE"
            . ' FROM ingredient i, generate_series(1, 204) AS n WHERE i.slug = :slug',
            ['slug' => $salt->getSlug()],
        );

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then : 200 rendus, le vrai nombre dit ; les noms « aaa » d'abord, l'ingrédient « zzz » est au-delà du plafond
        $this->assertSame(205, $result->totalItems);
        $this->assertCount(IngredientToFixLoader::LIMIT, $result->member);
        $this->assertSame('aaa 0001', $result->member[0]->name);
        $this->assertSame('aaa 0200', $result->member[199]->name);
    }

    public function testTheReadCostsTwoQueriesWhateverTheNumberOfIngredients(): void
    {
        // Given : cinq ingrédients à régler, dont un avec une conversion propre qui ne suffit pas
        foreach (range(1, 5) as $index) {
            $this->saved("sel $index", new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isKilo());
        }
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then : les ingrédients avec leurs unités et leur rayon, puis les conversions
        $this->assertSame(5, $result->totalItems);
        $this->assertSame(['SELECT ingredient' => 1, 'SELECT conversion' => 1], $counter->byTable());
    }

    public function testAUserWithoutIngredientHasNothingToFixAndNoConversionIsRead(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->loader->toFix($this->user->getId());

        // Then
        $this->assertSame([[], 0], [$result->member, $result->totalItems]);
        $this->assertSame(['SELECT ingredient' => 1], $counter->byTable());
    }

    private function saved(string $name, UnitBuilder $cooking, UnitBuilder $purchase, ?User $owner = null): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withCreator($owner ?? $this->user, true)
            ->withDefaultCookingUnit($cooking)->withDefaultPurchaseUnit($purchase)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }
}
