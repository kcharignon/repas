<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientMergePlanner;
use Repas\Shared\Domain\Event\IngredientRemovedEvent;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DriveIngredientReferencesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientDistinctInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientMergeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

/**
 * Fusionner deux ingrédients d'un utilisateur (ADR 0028 décision 9, ING-005) : « Tomates » (`B`, absorbé) dans « Tomate » (`A`, gardé).
 * Les deux s'achètent au kilo et se cuisinent au gramme ; le kilo vaut mille grammes (conversion commune) et l'unité vaut cent
 * cinquante grammes pour chacun (conversion propre). Les recettes, les listes et les choix du drive sont des tableaux : le SQL est prouvé
 * sur la vraie base (`IngredientMergeRepositoryTest`, `DriveIngredientReferencesRepositoryTest`).
 */
class MergeIngredientsHandlerTest extends TestCase
{
    private const string USER = 'user-id';
    private const string OTHER = 'other-id';
    private const string KEPT = 'tomateuser-id';
    private const string ABSORBED = 'tomatesuser-id';

    private IngredientInMemoryRepository $ingredientRepository;
    private ConversionInMemoryRepository $conversionRepository;
    private IngredientMergeInMemoryRepository $mergeRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private IngredientDistinctInMemoryRepository $distinctRepository;
    private DriveIngredientReferencesInMemoryRepository $driveReferences;
    private SpyEventDispatcher $eventDispatcher;
    private MergeIngredientsHandler $handler;

    protected function setUp(): void
    {
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER),
            self::tomato('Tomates', self::USER),
        ]);
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isKiloToGramme()->withId('common-kilo')->build(),
            self::unitConversion('kept-unit', 'Tomate', self::USER),
            self::unitConversion('absorbed-unit', 'Tomates', self::USER),
        ]);
        $this->mergeRepository = new IngredientMergeInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->distinctRepository = new IngredientDistinctInMemoryRepository();
        $this->driveReferences = new DriveIngredientReferencesInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
    }

    private function handlerFor(IngredientInMemoryRepository $ingredients, ConversionInMemoryRepository $conversions): MergeIngredientsHandler
    {
        // Le double du port lit et écrit les conversions et les ingrédients de CE handler (ceux du test)
        $this->mergeRepository->conversions = $conversions;
        $this->mergeRepository->ingredients = $ingredients;

        return new MergeIngredientsHandler(
            $ingredients,
            $this->mergeRepository,
            $this->aliasRepository,
            $this->distinctRepository,
            $conversions,
            new ConversionService($conversions, new UnitInMemoryRepository()),
            new IngredientMergePlanner(),
            $this->driveReferences,
            $this->eventDispatcher,
            new FrozenClock(new DateTimeImmutable('2026-10-21 03:00:00')),
            new UuidGenerator(),
        );
    }

    /**
     * @param list<UnitBuilder>|null $compatible les unités compatibles (le gramme et le kilo par défaut)
     */
    private static function tomato(string $name, ?string $creator, ?string $adoptedFrom = null, ?UnitBuilder $purchase = null, ?DepartmentBuilder $department = null, ?array $compatible = null): Ingredient
    {
        $builder = new IngredientBuilder()
            ->withName($name)
            ->withDepartment($department ?? new DepartmentBuilder()->isCereal())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit($purchase ?? new UnitBuilder()->isKilo())
            ->withCreator($creator === null ? null : new UserBuilder()->withId($creator)->build(), true)
            ->withAdoptedFrom($adoptedFrom);

        return ($compatible === null ? $builder : $builder->withCompatibleUnits($compatible))->build();
    }

    /** Une conversion PROPRE : `tranche` -> `gramme` (25 g la tranche par défaut). */
    private static function sliceConversion(string $id, string $ingredientName, ?string $creator, float $coefficient = 25.0)
    {
        return new ConversionBuilder()->withId($id)->withStartUnit(new UnitBuilder()->isSlice())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient($coefficient)
            ->withIngredient(new IngredientBuilder()->withName($ingredientName)->withCreator($creator === null ? null : new UserBuilder()->withId($creator)->build(), true))->build();
    }

    private static function unitConversion(string $id, string $ingredientName, ?string $creator)
    {
        return new ConversionBuilder()->withId($id)->withStartUnit(new UnitBuilder()->isUnite())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(150)
            ->withIngredient(new IngredientBuilder()->withName($ingredientName)->withCreator($creator === null ? null : new UserBuilder()->withId($creator)->build(), true))->build();
    }

    private function merge(string $userId = self::USER, string $absorbed = self::ABSORBED, string $kept = self::KEPT): void
    {
        ($this->handler)(new MergeIngredientsCommand($userId, $absorbed, $kept));
    }

    /**
     * @return array{id: string, ingredient: string, quantity: float, unit: string, checked: bool}
     */
    private static function row(string $id, string $ingredient, float $quantity, string $unit = 'kilo', bool $checked = false): array
    {
        return ['id' => $id, 'ingredient' => $ingredient, 'quantity' => $quantity, 'unit' => $unit, 'checked' => $checked];
    }

    /**
     * @return array{id: string, ingredient: string, quantity: float, unit: string}
     */
    private static function item(string $id, string $ingredient, float $quantity, string $unit = 'kilo'): array
    {
        return ['id' => $id, 'ingredient' => $ingredient, 'quantity' => $quantity, 'unit' => $unit];
    }

    private function recipe(string $id, array $ingredients, string $unit = 'gramme', string $author = self::USER): void
    {
        $this->mergeRepository->recipes[$id] = ['author' => $author, 'version' => 3, 'rows' => array_map(static fn(string $ingredient): array => ['ingredient' => $ingredient, 'unit' => $unit], $ingredients)];
    }

    private function list(string $id, array $rows = [], array $items = [], string $status = 'ACTIVE', string $owner = self::USER): void
    {
        $this->mergeRepository->lists[$id] = ['owner' => $owner, 'version' => 5, 'status' => $status, 'rows' => $rows, 'items' => $items];
    }

    public function testTheAbsorbedIngredientDisappearsAndEverythingThatUsedItUsesTheKeptOne(): void
    {
        // Given : « Tomates » dans deux recettes (dont une qui contient aussi « Tomate »), « Tomate » dans une troisième, et trois listes
        $this->recipe('r1', [self::ABSORBED]);
        $this->recipe('r2', [self::ABSORBED, self::KEPT]);
        $this->recipe('r3', [self::KEPT]);
        $this->list('l1', [self::row('x1', self::ABSORBED, 2.0)]);
        $this->list('l2', [self::row('x2', self::ABSORBED, 1.5)], [self::item('y2', self::ABSORBED, 3.0)]);
        $this->list('l3', [self::row('x3', self::KEPT, 4.0)]);

        // When
        $this->merge();

        // Then : plus d'ingrédient absorbé, plus de ligne dessus
        $this->assertFalse($this->ingredientRepository->exists(self::ABSORBED));
        $this->assertTrue($this->ingredientRepository->exists(self::KEPT));
        foreach ($this->mergeRepository->recipes as $recipe) {
            $this->assertNotContains(self::ABSORBED, array_column($recipe['rows'], 'ingredient'));
        }
        // les recettes concernées passent sur « Tomate », la recette qui contenait les deux garde ses deux lignes, leurs versions montent
        $this->assertSame([self::KEPT], array_column($this->mergeRepository->recipes['r1']['rows'], 'ingredient'));
        $this->assertSame([self::KEPT, self::KEPT], array_column($this->mergeRepository->recipes['r2']['rows'], 'ingredient'));
        $this->assertSame(['r1' => 4, 'r2' => 4, 'r3' => 3], array_map(static fn(array $recipe): int => $recipe['version'], $this->mergeRepository->recipes));
        // les listes : la ligne de « Tomates » devient une ligne de « Tomate », quantité et unité inchangées ; l'ajout à la main aussi
        $this->assertSame([[self::KEPT, 2.0, 'kilo']], array_map(static fn(array $row): array => [$row['ingredient'], $row['quantity'], $row['unit']], $this->mergeRepository->lists['l1']['rows']));
        $this->assertSame([[self::KEPT, 3.0, 'kilo']], array_map(static fn(array $item): array => [$item['ingredient'], $item['quantity'], $item['unit']], $this->mergeRepository->lists['l2']['items']));
        // seules les listes concernées montent
        $this->assertSame([6, 6, 5], array_column($this->mergeRepository->lists, 'version'));
        $this->assertSame(['lock', 'usageOf', 'repoint'], $this->mergeRepository->calls);
    }

    public function testTheKeptIngredientKeepsEverythingAndTheAbsorbedOneLeavesNothingBehind(): void
    {
        // Given
        $kept = $this->ingredientRepository->findOneBySlug(self::KEPT);

        // When
        $this->merge();

        // Then : « Tomate » est intact, ses conversions propres aussi ; celles de « Tomates » ne sont pas reportées, elles disparaissent
        $after = $this->ingredientRepository->findOneBySlug(self::KEPT);
        $this->assertSame([$kept->getName(), $kept->getImage(), $kept->getDepartment()->getSlug(), $kept->getDefaultPurchaseUnit()->getSlug()], [$after->getName(), $after->getImage(), $after->getDepartment()->getSlug(), $after->getDefaultPurchaseUnit()->getSlug()]);
        $conversionIds = array_values(array_map(static fn($conversion) => $conversion->getId(), $this->conversionRepository->findAll()->toArray()));
        sort($conversionIds);
        $this->assertSame(['common-kilo', 'kept-unit'], $conversionIds);
    }

    public function testAListThatHasBothKeepsOneRowWithTheSumAndIsTickedOnlyIfBothWere(): void
    {
        // Given : quatre listes qui ont les deux ingrédients, chacune dans un état de cochage
        $this->list('both-ticked', [self::row('a1', self::KEPT, 2.0, checked: true), self::row('b1', self::ABSORBED, 1.0, checked: true)]);
        $this->list('kept-ticked', [self::row('a2', self::KEPT, 2.0, checked: true), self::row('b2', self::ABSORBED, 1.0)]);
        $this->list('absorbed-ticked', [self::row('a3', self::KEPT, 2.0), self::row('b3', self::ABSORBED, 1.0, checked: true)]);
        $this->list('none-ticked', [self::row('a4', self::KEPT, 2.5), self::row('b4', self::ABSORBED, 1.25)]);
        // Une liste qui n'a que « Tomates », cochée : elle reste cochée
        $this->list('only-absorbed', [self::row('b5', self::ABSORBED, 1.0, checked: true)]);

        // When
        $this->merge();

        // Then
        $result = fn(string $list): array => array_map(static fn(array $row): array => [$row['id'], $row['ingredient'], $row['quantity'], $row['checked']], $this->mergeRepository->lists[$list]['rows']);
        $this->assertSame([['a1', self::KEPT, 3.0, true]], $result('both-ticked'));
        $this->assertSame([['a2', self::KEPT, 3.0, false]], $result('kept-ticked'));
        $this->assertSame([['a3', self::KEPT, 3.0, false]], $result('absorbed-ticked'));
        $this->assertSame([['a4', self::KEPT, 3.75, false]], $result('none-ticked'));
        $this->assertSame([['b5', self::KEPT, 1.0, true]], $result('only-absorbed'));
    }

    public function testManualIngredientsOfBothAreSummedInTheKeptUnit(): void
    {
        // Given
        $this->list('l1', items: [self::item('y1', self::KEPT, 2.0), self::item('y2', self::ABSORBED, 3.0)]);

        // When
        $this->merge();

        // Then
        $this->assertSame([['y1', self::KEPT, 5.0, 'kilo']], array_map(static fn(array $item): array => [$item['id'], $item['ingredient'], $item['quantity'], $item['unit']], $this->mergeRepository->lists['l1']['items']));
    }

    public function testAnotherPurchaseUnitConvertsTheQuantitiesOfTheListsAndRoundsTheCountersUp(): void
    {
        // Given : « Tomates » s'achète au kilo, « Tomate » au gramme ; une liste a une ligne de 1,5 kilo et 2 ajouts à la main
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER, purchase: new UnitBuilder()->isGramme()),
            self::tomato('Tomates', self::USER),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
        $this->list('l1', [self::row('x1', self::ABSORBED, 1.5)], [self::item('y1', self::ABSORBED, 2.0)]);
        // une autre liste a déjà la ligne de « Tomate » (en gramme) : la quantité de « Tomates » s'y ajoute, convertie
        $this->list('l2', [self::row('x2a', self::KEPT, 500.0, 'gramme'), self::row('x2b', self::ABSORBED, 0.25)]);

        // When
        $this->merge();

        // Then : 1,5 kilo = 1 500 grammes, 2 kilos = 2 000 pas de gramme ; 500 g + 250 g = 750 g
        $this->assertSame([[self::KEPT, 1500.0, 'gramme']], array_map(static fn(array $row): array => [$row['ingredient'], $row['quantity'], $row['unit']], $this->mergeRepository->lists['l1']['rows']));
        $this->assertSame([[self::KEPT, 2000.0, 'gramme']], array_map(static fn(array $item): array => [$item['ingredient'], $item['quantity'], $item['unit']], $this->mergeRepository->lists['l1']['items']));
        $this->assertSame([['x2a', self::KEPT, 750.0, 'gramme']], array_map(static fn(array $row): array => [$row['id'], $row['ingredient'], $row['quantity'], $row['unit']], $this->mergeRepository->lists['l2']['rows']));
    }

    public function testACounterNeverRoundsDownToZeroAndRoundsUp(): void
    {
        // Given : « Tomates » s'achète au gramme, « Tomate » au kilo : 1 gramme = 0,001 kilo, 1 500 grammes = 1,5 kilo
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER),
            self::tomato('Tomates', self::USER, purchase: new UnitBuilder()->isGramme()),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
        $this->list('l1', items: [self::item('y1', self::ABSORBED, 1.0, 'gramme'), self::item('y2', self::ABSORBED, 1500.0, 'gramme')]);
        $this->list('l2', items: [self::item('y3', self::ABSORBED, 1500.0, 'gramme')]);

        // When
        $this->merge();

        // Then : 1 g donne 1 pas (et non 0), 1 500 g donnent 2 pas ; ensemble 3 ; seul : 2
        $this->assertSame([['y1', self::KEPT, 3.0, 'kilo']], array_map(static fn(array $item): array => [$item['id'], $item['ingredient'], $item['quantity'], $item['unit']], $this->mergeRepository->lists['l1']['items']));
        $this->assertSame([['y3', self::KEPT, 2.0, 'kilo']], array_map(static fn(array $item): array => [$item['id'], $item['ingredient'], $item['quantity'], $item['unit']], $this->mergeRepository->lists['l2']['items']));
    }

    public function testAnUnitThatTheKeptIngredientCannotConvertRefusesTheWholeMergeAndWritesNothing(): void
    {
        // Given : une recette de « Tomates » utilise des tranches, « Tomate » ne sait pas les convertir en kilo
        $this->recipe('r1', [self::ABSORBED], 'tranche');
        $this->recipe('r2', [self::ABSORBED]);
        $this->list('l1', [self::row('x1', self::ABSORBED, 2.0)]);
        $this->driveReferences->choices = ['c1' => ['user' => self::USER, 'slug' => self::ABSORBED, 'products' => 2]];
        $recipesBefore = $this->mergeRepository->recipes;
        $listsBefore = $this->mergeRepository->lists;

        // Then
        $this->expectExceptionObject(IngredientException::mergeUnitMissing());

        try {
            // When
            $this->merge();
        } finally {
            // Then : rien n'a bougé, ni les lignes, ni les versions, ni les alias, ni le drive, ni l'ingrédient, ni les statistiques
            $this->assertSame($recipesBefore, $this->mergeRepository->recipes);
            $this->assertSame($listsBefore, $this->mergeRepository->lists);
            $this->assertSame(['lock', 'usageOf'], $this->mergeRepository->calls);
            $this->assertSame([], $this->aliasRepository->all());
            $this->assertSame([], $this->driveReferences->mergeCalls);
            $this->assertSame('c1', array_key_first($this->driveReferences->choices));
            $this->assertTrue($this->ingredientRepository->exists(self::ABSORBED));
            $this->assertCount(3, $this->conversionRepository->findAll());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testAListUnitThatTheKeptIngredientCannotConvertRefusesTheMerge(): void
    {
        // Given : « Tomates » s'achète au litre (une ligne de liste en litre), « Tomate » ne sait pas passer du litre au kilo
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER),
            self::tomato('Tomates', self::USER, purchase: new UnitBuilder()->isLiter()),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
        $this->list('l1', [self::row('x1', self::ABSORBED, 2.0, 'litre')]);

        // Then
        $this->expectExceptionObject(IngredientException::mergeUnitMissing());

        // When
        $this->merge();
    }

    public function testAnUnitOfTheAbsorbedIngredientThatTheKeptOneConvertsOnlyThroughItsOwnConversionsIsAccepted(): void
    {
        // Given : une recette de « Tomates » en unité ; « Tomate » a la conversion unité -> gramme, « Tomates » aurait la sienne aussi
        $this->recipe('r1', [self::ABSORBED], 'unite');

        // When
        $this->merge();

        // Then
        $this->assertFalse($this->ingredientRepository->exists(self::ABSORBED));
    }

    public function testTwoIngredientsEachUsedByOneRecipeEndUpUsedByTwoAndNoLineIsLost(): void
    {
        // Given : « Tomate » est dans la recette R1, « Tomates » dans la recette R2 (le cas de deux « Melon », chacun dans une recette)
        $this->recipe('r1', [self::KEPT]);
        $this->recipe('r2', [self::ABSORBED]);
        $usage = $this->mergeRepository->usageOf(self::USER, self::ABSORBED, self::KEPT, 'kilo');

        // When
        $this->merge();

        // Then : l'aperçu annonçait 1 recette qui se déplace et 2 qui utiliseront « Tomate » ; la recette de « Tomates » pointe vers « Tomate »
        $this->assertSame([1, 2], [$usage->recipeCount, $usage->resultRecipeCount]);
        $this->assertSame([self::KEPT], array_column($this->mergeRepository->recipes['r2']['rows'], 'ingredient'));
        $this->assertSame([self::KEPT], array_column($this->mergeRepository->recipes['r1']['rows'], 'ingredient'));
        $after = $this->mergeRepository->usageOf(self::USER, 'rien', self::KEPT, 'kilo');
        $this->assertSame(2, $after->resultRecipeCount, 'après la fusion, deux recettes utilisent « Tomate »');
    }

    public function testTheUsageCountsARecipeAndAListThatUseBothIngredientsOnceInTheTotalAfterTheMerge(): void
    {
        // Given : R1 utilise les deux, R2 seulement « Tomates », R3 seulement « Tomate » ; même chose pour trois listes (une ligne ou un ajout à la main)
        $this->recipe('r1', [self::KEPT, self::ABSORBED]);
        $this->recipe('r2', [self::ABSORBED]);
        $this->recipe('r3', [self::KEPT]);
        $this->list('l1', [self::row('x1', self::KEPT, 1.0)], [self::item('y1', self::ABSORBED, 1.0)]);
        $this->list('l2', [self::row('x2', self::ABSORBED, 1.0)]);
        $this->list('l3', [self::row('x3', self::KEPT, 1.0)]);

        // When
        $usage = $this->mergeRepository->usageOf(self::USER, self::ABSORBED, self::KEPT, 'kilo');

        // Then : 2 recettes et 2 listes se déplacent, mais 3 recettes et 3 listes utiliseront « Tomate » (l'union)
        $this->assertSame([2, 2, 3, 3], [$usage->recipeCount, $usage->listCount, $usage->resultRecipeCount, $usage->resultListCount]);
    }

    private function absorbedKnowsTheSlice(): void
    {
        // « Tomates » sait compter les tranches (25 g) et les déclare compatibles ; « Tomate » ne sait rien des tranches
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER),
            self::tomato('Tomates', self::USER, compatible: [new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isSlice()]),
        ]);
        $this->conversionRepository->save(self::sliceConversion('absorbed-slice', 'Tomates', self::USER));
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
    }

    public function testAConversionTheKeptIngredientLacksIsRecoveredFromTheAbsorbedOneInsteadOfRefusingTheMerge(): void
    {
        // Given : une recette de « Tomates » en tranches ; avant, la fusion était refusée (« Tomate » ne convertit pas la tranche)
        $this->absorbedKnowsTheSlice();
        $this->recipe('r1', [self::ABSORBED], 'tranche');

        // When
        $this->merge();

        // Then : la fusion a lieu, la recette est sur « Tomate », toujours en tranches
        $this->assertFalse($this->ingredientRepository->exists(self::ABSORBED));
        $this->assertSame([[self::KEPT, 'tranche']], array_map(static fn(array $row): array => [$row['ingredient'], $row['unit']], $this->mergeRepository->recipes['r1']['rows']));
        // la conversion a été copiée sur « Tomate » (nouvel identifiant, mêmes unités et même coefficient), celles de « Tomates » ont disparu
        $conversions = $this->conversionRepository->findAll()->toArray();
        $copy = array_values(array_filter($conversions, static fn($conversion): bool => $conversion->getIngredient()?->getSlug() === self::KEPT && $conversion->getStartUnit()->getSlug() === 'tranche'));
        $this->assertCount(1, $copy);
        $this->assertSame(['tranche', 'gramme', 25.0], [$copy[0]->getStartUnit()->getSlug(), $copy[0]->getEndUnit()->getSlug(), $copy[0]->getCoefficient()]);
        $this->assertNotSame('absorbed-slice', $copy[0]->getId());
        $this->assertSame([], array_filter($conversions, static fn($conversion): bool => $conversion->getIngredient()?->getSlug() === self::ABSORBED));
        $this->assertCount(3, $conversions, 'la commune, la sienne, la sienne récupérée et rien de plus');
        // et les quantités de la recette se convertissent désormais : 4 tranches = 100 g = 0,1 kilo
        $service = new ConversionService($this->conversionRepository, new UnitInMemoryRepository());
        $this->assertEqualsWithDelta(0.1, $service->convertTo($this->ingredientRepository->findOneBySlug(self::KEPT), 4, new UnitBuilder()->isSlice()->build(), new UnitBuilder()->isKilo()->build()), 1e-12);
        // l'ordre : la conversion est récupérée AVANT de repointer les lignes
        $this->assertSame(['lock', 'usageOf', 'recoverConversions', 'repoint'], $this->mergeRepository->calls);
    }

    public function testTheCompatibleUnitsOfTheAbsorbedIngredientThatTheMovedRowsUseJoinTheKeptOnes(): void
    {
        // Given
        $this->absorbedKnowsTheSlice();
        $this->recipe('r1', [self::ABSORBED], 'tranche');

        // When
        $this->merge();

        // Then : l'éditeur de recette propose la tranche pour « Tomate », sans perdre ses unités
        $this->assertSame(['gramme', 'kilo', 'tranche'], array_map(static fn(Unit $unit): string => $unit->getSlug(), $this->ingredientRepository->findOneBySlug(self::KEPT)->getCompatibleUnits()->toArray()));
    }

    public function testAnUnusedCompatibleUnitOfTheAbsorbedIngredientIsNotAdded(): void
    {
        // Given : « Tomates » déclare la tranche compatible mais aucune ligne ne l'utilise
        $this->absorbedKnowsTheSlice();
        $this->recipe('r1', [self::ABSORBED], 'gramme');

        // When
        $this->merge();

        // Then : « Tomate » garde ses unités, la conversion de la tranche est tout de même récupérée (elle lui manquait)
        $this->assertSame(['gramme', 'kilo'], array_map(static fn(Unit $unit): string => $unit->getSlug(), $this->ingredientRepository->findOneBySlug(self::KEPT)->getCompatibleUnits()->toArray()));
        $this->assertSame(['lock', 'usageOf', 'recoverConversions', 'repoint'], $this->mergeRepository->calls);
    }

    public function testWhenTheCoefficientsDifferTheKeptIngredientWinsAndNothingIsCopied(): void
    {
        // Given : « Tomates » compte 160 g l'unité, « Tomate » 150 g
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isKiloToGramme()->withId('common-kilo')->build(),
            self::unitConversion('kept-unit', 'Tomate', self::USER),
            new ConversionBuilder()->withId('absorbed-unit')->withStartUnit(new UnitBuilder()->isUnite())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(160)
                ->withIngredient(new IngredientBuilder()->withName('Tomates')->withCreator(new UserBuilder()->withId(self::USER)->build(), true))->build(),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
        $this->recipe('r1', [self::ABSORBED], 'unite');

        // When
        $this->merge();

        // Then : la fusion a lieu, celle de « Tomate » n'est pas écrasée, rien n'est copié
        $conversions = $this->conversionRepository->findAll();
        $this->assertSame(['common-kilo', 'kept-unit'], array_values(array_map(static fn($conversion): string => $conversion->getId(), $conversions->toArray())));
        $this->assertSame(150.0, $this->conversionRepository->findById('kept-unit')->getCoefficient());
        $this->assertSame(['lock', 'usageOf', 'repoint'], $this->mergeRepository->calls);
    }

    public function testAConversionThatLiftsAListUnitRefusalAlsoConvertsTheListQuantities(): void
    {
        // Given : « Tomates » s'achète au litre et sait qu'un litre pèse 1 000 g ; « Tomate » s'achète au kilo et ne connaît pas le litre
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER),
            self::tomato('Tomates', self::USER, purchase: new UnitBuilder()->isLiter()),
        ]);
        $this->conversionRepository->save(new ConversionBuilder()->withId('absorbed-liter')->withStartUnit(new UnitBuilder()->isLiter())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(1000)
            ->withIngredient(new IngredientBuilder()->withName('Tomates')->withCreator(new UserBuilder()->withId(self::USER)->build(), true))->build());
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
        $this->list('l1', [self::row('x1', self::ABSORBED, 2.0, 'litre')]);

        // When
        $this->merge();

        // Then : refusée avant, acceptée maintenant ; 2 litres = 2 000 g = 2 kilos
        $this->assertSame([[self::KEPT, 2.0, 'kilo']], array_map(static fn(array $row): array => [$row['ingredient'], $row['quantity'], $row['unit']], $this->mergeRepository->lists['l1']['rows']));
    }

    public function testAnUnitThatStaysImpossibleAfterTheRecoveryRefusesTheMergeAndCopiesNothing(): void
    {
        // Given : « Tomates » sait compter les tranches, mais une autre recette utilise des litres que personne ne convertit
        $this->absorbedKnowsTheSlice();
        $this->recipe('r1', [self::ABSORBED], 'tranche');
        $this->recipe('r2', [self::ABSORBED], 'litre');
        $recipesBefore = $this->mergeRepository->recipes;
        $conversionsBefore = $this->conversionRepository->findAll()->toArray();

        try {
            // When
            $this->merge();
            $this->fail('La fusion aurait dû être refusée : le litre ne se convertit nulle part.');
        } catch (IngredientException $exception) {
            // Then : 422, rien n'a été copié ni déplacé, les conversions de « Tomates » sont encore là
            $this->assertSame(['MERGE_UNIT_MISSING', 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame(['lock', 'usageOf'], $this->mergeRepository->calls);
        $this->assertSame($recipesBefore, $this->mergeRepository->recipes);
        $this->assertEquals($conversionsBefore, $this->conversionRepository->findAll()->toArray());
        $this->assertTrue($this->ingredientRepository->exists(self::ABSORBED));
        $this->assertSame(['gramme', 'kilo'], array_map(static fn(Unit $unit): string => $unit->getSlug(), $this->ingredientRepository->findOneBySlug(self::KEPT)->getCompatibleUnits()->toArray()));
    }

    public function testAConversionThatDisappearsDuringTheCopyCancelsTheMerge(): void
    {
        // Given : une écriture concurrente retire la conversion de « Tomates » entre l'analyse et la copie
        $this->absorbedKnowsTheSlice();
        $this->recipe('r1', [self::ABSORBED], 'tranche');
        $this->mergeRepository->loseAConversion = true;
        $recipesBefore = $this->mergeRepository->recipes;

        // Then
        $this->expectExceptionObject(IngredientException::mergeConflict());

        try {
            // When
            $this->merge();
        } finally {
            // Then : rien n'est repointé, supprimé ni compté
            $this->assertSame(['lock', 'usageOf', 'recoverConversions'], $this->mergeRepository->calls);
            $this->assertSame($recipesBefore, $this->mergeRepository->recipes);
            $this->assertTrue($this->ingredientRepository->exists(self::ABSORBED));
            $this->assertSame([], $this->aliasRepository->all());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testReplayingARecoveringMergeCopiesNothingAgain(): void
    {
        // Given : la fusion avec récupération a réussi
        $this->absorbedKnowsTheSlice();
        $this->recipe('r1', [self::ABSORBED], 'tranche');
        $this->merge();
        $conversionIds = array_map(static fn($conversion): string => $conversion->getId(), $this->conversionRepository->findAll()->toArray());

        // When : le client renvoie la même demande
        $this->merge();

        // Then : les mêmes conversions, une seule récupération
        $this->assertSame($conversionIds, array_map(static fn($conversion): string => $conversion->getId(), $this->conversionRepository->findAll()->toArray()));
        $this->assertSame(1, count(array_filter($this->mergeRepository->calls, static fn(string $call): bool => $call === 'recoverConversions')));
    }

    public function testASentListThatUsesTheAbsorbedOrTheKeptIngredientRefusesTheMerge(): void
    {
        foreach ([self::ABSORBED, self::KEPT] as $used) {
            // Given : une liste envoyée au drive qui utilise l'un des deux
            $this->setUp();
            $this->list('sent', [self::row('x1', $used, 2.0)], status: 'SENT');
            $this->list('active', [self::row('x2', self::ABSORBED, 1.0)]);
            $listsBefore = $this->mergeRepository->lists;

            try {
                // When
                $this->merge();
                $this->fail('la fusion devait être refusée');
            } catch (IngredientException $exception) {
                // Then : rien n'a été écrit, y compris dans la liste active
                $this->assertSame(['INGREDIENT_IN_SENT_LIST', 409], [$exception->getMessage(), $exception->getCode()]);
                $this->assertSame($listsBefore, $this->mergeRepository->lists);
                $this->assertTrue($this->ingredientRepository->exists(self::ABSORBED));
            }
        }
    }

    public function testOncetheSentListIsReopenedTheMergeGoesThrough(): void
    {
        // Given : la liste qui était envoyée est redevenue active
        $this->list('reopened', [self::row('x1', self::ABSORBED, 2.0)], status: 'ACTIVE');

        // When
        $this->merge();

        // Then
        $this->assertSame(self::KEPT, $this->mergeRepository->lists['reopened']['rows'][0]['ingredient']);
    }

    public function testAPausedOrCompletedListIsMergedLikeAnyOther(): void
    {
        // Given
        $this->list('paused', [self::row('x1', self::ABSORBED, 2.0)], status: 'PAUSED');
        $this->list('completed', [self::row('x2', self::ABSORBED, 1.0)], status: 'COMPLETED');

        // When
        $this->merge();

        // Then
        $this->assertSame([self::KEPT, self::KEPT], [$this->mergeRepository->lists['paused']['rows'][0]['ingredient'], $this->mergeRepository->lists['completed']['rows'][0]['ingredient']]);
    }

    public function testAnAdministratorHasNoWayIn(): void
    {
        // Given : les deux ingrédients sont à un autre utilisateur ; celui qui appelle est administrateur, mais le cas d'usage ne connaît que
        // l'identifiant de l'utilisateur du jeton
        $this->list('l1', [self::row('x1', self::ABSORBED, 2.0)]);
        $listsBefore = $this->mergeRepository->lists;

        try {
            // When
            $this->merge('admin-id');
            $this->fail('un administrateur ne fusionne pas les ingrédients d\'un autre');
        } catch (IngredientException $exception) {
            // Then : l'ingrédient d'un autre est inconnu
            $this->assertSame(404, $exception->getCode());
            $this->assertSame($listsBefore, $this->mergeRepository->lists);
            $this->assertTrue($this->ingredientRepository->exists(self::ABSORBED));
        }
    }

    public function testTheIngredientOfAnotherUserIsUnknownOnEitherSide(): void
    {
        $theirs = 'tomate-de-lautreother-id';
        foreach ([[self::ABSORBED, $theirs], [$theirs, self::KEPT]] as [$absorbed, $kept]) {
            // Given : un troisième ingrédient, à un autre utilisateur
            $this->setUp();
            $this->ingredientRepository->save(self::tomato('tomate de lautre', self::OTHER));
            $this->assertTrue($this->ingredientRepository->exists($theirs));
            $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);

            try {
                // When
                $this->merge(self::USER, $absorbed, $kept);
                $this->fail('l\'ingrédient d\'un autre ne se fusionne pas');
            } catch (IngredientException $exception) {
                // Then : il est inconnu, comme un slug qui n'existe pas, et rien n'a été lu ni écrit
                $this->assertSame(404, $exception->getCode());
                $this->assertSame(['lock'], $this->mergeRepository->calls);
            }
        }
    }

    public function testAnIngredientIsNotMergedIntoItself(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::cannotMergeIntoItself());

        // When
        $this->merge(self::USER, self::KEPT, self::KEPT);
    }

    public function testAnUnknownIngredientIsRefusedWith404(): void
    {
        foreach ([['inconnu', self::KEPT], [self::ABSORBED, 'inconnu']] as [$absorbed, $kept]) {
            try {
                // When
                $this->merge(self::USER, $absorbed, $kept);
                $this->fail('un ingrédient inconnu');
            } catch (IngredientException $exception) {
                // Then
                $this->assertSame(404, $exception->getCode());
            }
        }
    }

    public function testALineOfAnotherUserOnTheAbsorbedIngredientStopsTheMerge(): void
    {
        // Given : une donnée héritée, la recette d'un autre utilisateur utilise « Tomates »
        $this->recipe('r1', [self::ABSORBED]);
        $this->recipe('legacy', [self::ABSORBED], author: self::OTHER);
        $recipesBefore = $this->mergeRepository->recipes;

        // Then
        $this->expectExceptionObject(IngredientException::usedByAnotherUser());

        try {
            // When
            $this->merge();
        } finally {
            $this->assertSame($recipesBefore, $this->mergeRepository->recipes);
        }
    }

    public function testAChoiceOfTheDriveOfAnotherUserOnTheAbsorbedIngredientStopsTheMerge(): void
    {
        // Given
        $this->driveReferences->choices = ['legacy' => ['user' => self::OTHER, 'slug' => self::ABSORBED, 'products' => 1]];

        // Then
        $this->expectExceptionObject(IngredientException::usedByAnotherUser());

        // When
        $this->merge();
    }

    public function testTheDriveIsMergedPerStoreTheKeptChoiceWinsElseTheAbsorbedOnePassesOver(): void
    {
        // Given : deux magasins ; dans le premier les deux ont un choix, dans le second seul « Tomates » ; un choix d'un autre utilisateur ne bouge pas
        $this->driveReferences->choices = [
            'kept-shop1' => ['user' => self::USER, 'slug' => self::KEPT, 'products' => 2, 'store' => 'shop1'],
            'absorbed-shop1' => ['user' => self::USER, 'slug' => self::ABSORBED, 'products' => 3, 'store' => 'shop1'],
            'absorbed-shop2' => ['user' => self::USER, 'slug' => self::ABSORBED, 'products' => 1, 'store' => 'shop2'],
            'other' => ['user' => self::OTHER, 'slug' => self::KEPT, 'products' => 1, 'store' => 'shop1'],
        ];

        // When
        $this->merge();

        // Then : le choix de « Tomate » est resté, celui de « Tomates » du premier magasin est supprimé, celui du second passe sur « Tomate »
        $this->assertSame(['kept-shop1' => self::KEPT, 'absorbed-shop2' => self::KEPT, 'other' => self::KEPT], array_map(static fn(array $choice): string => $choice['slug'], $this->driveReferences->choices));
        $this->assertSame([['user' => self::USER, 'absorbed' => self::ABSORBED, 'kept' => self::KEPT]], $this->driveReferences->mergeCalls);
        $this->assertSame(2, $this->driveReferences->choices['kept-shop1']['products']);
    }

    public function testAnIngredientNobodyUsesIsJustRemoved(): void
    {
        // When
        $this->merge();

        // Then
        $this->assertFalse($this->ingredientRepository->exists(self::ABSORBED));
        $this->assertSame(['lock', 'usageOf', 'repoint'], $this->mergeRepository->calls);
    }

    public function testTheAliasesFollowAndOldSlugsAreResolved(): void
    {
        // Given : l'utilisateur avait adopté le commun « tomates » dans « Tomates » ; un ancien alias de fusion désigne « Tomates » ; un autre utilisateur a
        // un alias de même clé
        $now = new DateTimeImmutable('2026-10-01 10:00:00');
        $this->aliasRepository = new IngredientAliasInMemoryRepository([
            IngredientAlias::create(self::USER, 'tomates', self::ABSORBED, AliasKind::ADOPTION, 'migration-2026-10-20', $now),
            IngredientAlias::create(self::USER, 'ancienne-tomate', self::ABSORBED, AliasKind::MERGE, 'merge', $now),
            IngredientAlias::create(self::OTHER, 'tomates', 'tomatesother-id', AliasKind::ADOPTION, 'migration-2026-10-20', $now),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);

        // When
        $this->merge();

        // Then : tout ce qui désignait « Tomates » désigne « Tomate », l'alias de la fusion existe, l'autre utilisateur n'est pas touché
        $this->assertSame(
            ['tomates' => self::KEPT, 'ancienne-tomate' => self::KEPT, self::ABSORBED => self::KEPT],
            $this->aliasRepository->resolve(self::USER, ['tomates', 'ancienne-tomate', self::ABSORBED]),
        );
        $alias = $this->aliasRepository->find(self::USER, self::ABSORBED);
        $this->assertSame([AliasKind::MERGE, 'merge', '2026-10-21 03:00:00'], [$alias->getKind(), $alias->getSource(), $alias->getCreatedAt()->format('Y-m-d H:i:s')]);
        $this->assertSame('tomatesother-id', $this->aliasRepository->resolve(self::OTHER, ['tomates'])['tomates']);
        // l'adoption retournée vers « Tomate » reste une adoption (le commun reste masqué), marquée par la fusion
        $this->assertSame([AliasKind::ADOPTION, 'merge'], [$this->aliasRepository->find(self::USER, 'tomates')->getKind(), $this->aliasRepository->find(self::USER, 'tomates')->getSource()]);
    }

    public function testTheDistinctPairsThatNameTheAbsorbedIngredientAreForgottenAndOnlyThose(): void
    {
        // Given : « Tomates » est écartée de « Tomate » et d'un autre ingrédient ; « Tomate » l'est d'un troisième ; un autre utilisateur a écarté « Tomates »
        $now = new DateTimeImmutable('2026-10-01 10:00:00');
        $this->distinctRepository = new IngredientDistinctInMemoryRepository([
            IngredientDistinctPair::create(self::USER, self::ABSORBED, self::KEPT, $now),
            IngredientDistinctPair::create(self::USER, 'poivre-user-id', self::ABSORBED, $now),
            IngredientDistinctPair::create(self::USER, self::KEPT, 'courgette-user-id', $now),
            IngredientDistinctPair::create(self::OTHER, self::ABSORBED, 'poivre-other-id', $now),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);

        // When
        $this->merge();

        // Then
        $this->assertSame(
            [self::USER . '|courgette-user-id|' . self::KEPT, self::OTHER . '|poivre-other-id|' . self::ABSORBED],
            array_map(static fn(IngredientDistinctPair $pair): string => $pair->getId(), $this->distinctRepository->all()),
        );
    }

    public function testAMergeThatIsRefusedForgetsNoDistinctPair(): void
    {
        // Given : une recette utilise des tranches que « Tomate » ne convertit pas
        $this->recipe('r1', [self::ABSORBED], 'tranche');
        $this->distinctRepository = new IngredientDistinctInMemoryRepository([IngredientDistinctPair::create(self::USER, self::ABSORBED, 'poivre-user-id', new DateTimeImmutable())]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);

        // When
        try {
            $this->merge();
            $this->fail('La fusion aurait dû être refusée.');
        } catch (IngredientException) {
        }

        // Then
        $this->assertCount(1, $this->distinctRepository->all());
    }

    public function testMergingACreatedIngredientDecrementsTheStatistic(): void
    {
        // When
        $this->merge();

        // Then
        $event = $this->eventDispatcher->getLastEventDispatched();
        $this->assertInstanceOf(IngredientRemovedEvent::class, $event);
        $this->assertSame(self::USER, $event->userId);
        $this->assertSame([self::ABSORBED], $event->ingredientSlugs->toArray());
        $this->assertCount(1, $this->eventDispatcher->eventDispatched);
    }

    public function testMergingAnAdoptionDoesNotTouchTheStatistic(): void
    {
        // Given : « Tomates » est l'adoption d'un commun
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER),
            self::tomato('Tomates', self::USER, adoptedFrom: 'tomates'),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);

        // When
        $this->merge();

        // Then : une adoption n'a jamais été comptée comme une création
        $this->assertFalse($this->ingredientRepository->exists(self::ABSORBED));
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
    }

    public function testTheKeptIngredientBeingAnAdoptionChangesNothingForTheStatistic(): void
    {
        // Given
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER, adoptedFrom: 'tomate'),
            self::tomato('Tomates', self::USER),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);

        // When
        $this->merge();

        // Then : « Tomates » avait été créé, il est décompté
        $this->assertCount(1, $this->eventDispatcher->eventDispatched);
    }

    public function testReplayingASuccessfulMergeAnswersNothingToDo(): void
    {
        // Given : la fusion a réussi
        $this->recipe('r1', [self::ABSORBED]);
        $this->merge();
        $recipesAfter = $this->mergeRepository->recipes;
        $dispatched = count($this->eventDispatcher->eventDispatched);

        // When : le client a perdu la réponse et renvoie la même demande
        $this->merge();

        // Then : rien de plus, ni ligne, ni version, ni événement
        $this->assertSame($recipesAfter, $this->mergeRepository->recipes);
        $this->assertCount($dispatched, $this->eventDispatcher->eventDispatched);
        $this->assertSame(['lock', 'usageOf', 'repoint', 'lock'], $this->mergeRepository->calls);
    }

    public function testReplayingTowardsAnotherIngredientThanTheAliasIsUnknown(): void
    {
        // Given : « Tomates » a été fusionnée dans « Tomate », puis l'utilisateur demande de la fusionner dans un autre ingrédient
        $this->ingredientRepository->save(self::tomato('Autre', self::USER));
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);
        $this->merge();

        // Then
        $this->expectException(IngredientException::class);
        $this->expectExceptionCode(404);

        // When
        $this->merge(self::USER, self::ABSORBED, 'autreuser-id');
    }

    public function testReplayingAMergeThatTheUserNeverAskedIsUnknown(): void
    {
        // Then : l'alias d'un autre utilisateur ne vaut pas
        $this->aliasRepository->save(IngredientAlias::create(self::OTHER, 'tomatesuser-id', self::KEPT, AliasKind::MERGE, 'merge', new DateTimeImmutable()));
        $this->ingredientRepository->delete($this->ingredientRepository->findOneBySlug(self::ABSORBED));
        $this->expectException(IngredientException::class);
        $this->expectExceptionCode(404);

        // When
        $this->merge();
    }

    public function testAConcurrentWriteThatLeavesAReferenceCancelsTheMerge(): void
    {
        // Given : une écriture concurrente laisse une référence à l'absorbé
        $this->mergeRepository->leaveAReference = true;
        $this->recipe('r1', [self::ABSORBED]);

        // Then : l'exception annule la transaction du bus, rien n'est supprimé par le handler
        $this->expectExceptionObject(IngredientException::mergeConflict());

        try {
            // When
            $this->merge();
        } finally {
            $this->assertTrue($this->ingredientRepository->exists(self::ABSORBED));
            $this->assertSame([], $this->driveReferences->mergeCalls);
            $this->assertSame([], $this->aliasRepository->all());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testAMergeAcrossDepartmentsKeepsTheDepartmentOfTheKeptIngredient(): void
    {
        // Given : « Tomates » est dans un autre rayon
        $this->ingredientRepository = new IngredientInMemoryRepository([
            self::tomato('Tomate', self::USER),
            self::tomato('Tomates', self::USER, department: new DepartmentBuilder()->isBakery()),
        ]);
        $this->handler = $this->handlerFor($this->ingredientRepository, $this->conversionRepository);

        // When
        $this->merge();

        // Then
        $this->assertSame('cereale', $this->ingredientRepository->findOneBySlug(self::KEPT)->getDepartment()->getSlug());
    }
}
