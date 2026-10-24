<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientMergeRepository;
use Repas\Repas\Domain\Model\MergeUnitMove;
use Repas\Repas\Domain\Model\UnitFactor;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;
use Repas\User\Domain\Model\User;

/**
 * Les lectures et les écritures en masse de la fusion de deux ingrédients (ING-005), sur la vraie base et avec des données de tous les
 * types : une recette qui contient les deux, une liste qui contient les deux, des ingrédients ajoutés à la main, des listes envoyées, des
 * lignes d'un autre utilisateur, des unités qui diffèrent. Les mêmes cas sont rejoués sur le double en mémoire du handler
 * (`MergeIngredientsHandlerTest`).
 */
class IngredientMergeRepositoryTest extends DatabaseTestCase
{
    private IngredientMergeRepository $repository;
    private MergeDataset $dataset;
    private User $user;
    private User $other;
    private Ingredient $kept;
    private Ingredient $absorbed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(IngredientMergeRepository::class);
        $this->dataset = new MergeDataset($this->entityManager, new DataPersister(static::getContainer()));
        $this->dataset->units();
        $this->user = $this->dataset->user();
        $this->other = $this->dataset->user();
        $this->kept = $this->dataset->ingredient('Tomate', $this->user);
        $this->absorbed = $this->dataset->ingredient('Tomates', $this->user);
    }

    private function usage(string $keptPurchaseUnit = 'kilo'): \Repas\Repas\Domain\Model\IngredientMergeUsage
    {
        return $this->repository->usageOf($this->user->getId(), $this->absorbed->getSlug(), $this->kept->getSlug(), $keptPurchaseUnit);
    }

    private function repoint(array $factors = [], string $keptPurchaseUnit = 'kilo'): \Repas\Repas\Domain\Model\IngredientMergeRepointed
    {
        $result = $this->repository->repoint($this->user->getId(), $this->absorbed->getSlug(), $this->kept->getSlug(), $keptPurchaseUnit, $factors);
        $this->entityManager->clear();

        return $result;
    }

    public function testUsageCountsTheRecipesAndTheListsOfTheUserThatUseTheAbsorbedIngredient(): void
    {
        // Given : « Tomates » dans deux recettes (l'une avec deux lignes en deux unités) et trois listes (une ligne ; un ajout à la main ; les deux)
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $user = $this->user->getId();
        $this->dataset->recipe('r1', $user, [[$b, 100.0, 'gramme'], [$b, 2.0, 'unite']]);
        $this->dataset->recipe('r2', $user, [[$b, 1.0, 'gramme'], [$a, 1.0, 'kilo']]);
        $this->dataset->recipe('r3', $user, [[$a, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l1', $user, rows: [['l1-b', $b, 2.0, 'kilo']]);
        $this->dataset->shoppingList('l2', $user, items: [['l2-b', $b, 3.0, 'kilo']]);
        $this->dataset->shoppingList('l3', $user, status: 'PAUSED', rows: [['l3-b', $b, 1.0, 'kilo'], ['l3-a', $a, 1.0, 'gramme']], items: [['l3-ib', $b, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l4', $user, rows: [['l4-a', $a, 5.0, 'kilo']]);

        // When
        $usage = $this->usage();

        // Then : une recette ou une liste compte une fois, quel que soit le nombre de ses lignes
        $this->assertSame([2, 3], [$usage->recipeCount, $usage->listCount]);
        $this->assertSame(['gramme', 'unite'], $usage->recipeUnitSlugs);
        // la ligne de l3 va dans l'unité de la ligne de « Tomate » de la même liste (le gramme), les autres dans l'unité d'achat (le kilo)
        $this->assertEquals([new MergeUnitMove('kilo', 'gramme'), new MergeUnitMove('kilo', 'kilo')], $usage->listMoves);
        $this->assertSame([0, false], [$usage->sentListCount, $usage->usedByAnotherUser]);
    }

    public function testUsageTotalsAfterTheMergeAreTheUnionOfTheRecipesAndTheListsOfBothIngredients(): void
    {
        // Given : une recette et une liste qui utilisent les deux, une recette et une liste de chacun, une recette d'un autre utilisateur
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $user = $this->user->getId();
        $this->dataset->recipe('r-both', $user, [[$a, 1.0, 'gramme'], [$b, 1.0, 'gramme']]);
        $this->dataset->recipe('r-b', $user, [[$b, 1.0, 'gramme']]);
        $this->dataset->recipe('r-a', $user, [[$a, 1.0, 'gramme']]);
        $this->dataset->recipe('r-theirs', $this->other->getId(), [[$a, 1.0, 'gramme']]);
        $this->dataset->shoppingList('l-both', $user, rows: [['l1', $a, 1.0, 'kilo']], items: [['l2', $b, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l-b', $user, items: [['l3', $b, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l-a', $user, rows: [['l4', $a, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l-theirs', $this->other->getId(), rows: [['l5', $a, 1.0, 'kilo']]);

        // When
        $usage = $this->usage();

        // Then : ce qui se déplace (celles de « Tomates »), et ce qui utilisera « Tomate » ensuite (l'union)
        $this->assertSame([2, 2], [$usage->recipeCount, $usage->listCount]);
        $this->assertSame([3, 3], [$usage->resultRecipeCount, $usage->resultListCount]);
        // le total annoncé est celui de la base d'après
        $this->repoint();
        $used = $this->dataset->usedBy($user, $a);
        $this->assertSame([3, 3], [$used['recipes'], $used['lists']]);
    }

    public function testUsageListsOnlyTheOwnConversionsOfTheAbsorbedIngredient(): void
    {
        // Given : « Tomates » a deux conversions propres ; « Tomate » en a une ; la conversion commune existe
        $this->dataset->conversion($this->kept);
        $this->dataset->conversion($this->absorbed, 160.0);
        $this->dataset->conversion($this->absorbed, 0.1, new UnitBuilder()->isSlice(), new UnitBuilder()->isKilo());

        // When
        $conversions = $this->usage()->absorbedConversions;

        // Then : les siennes seulement, dans l'ordre (départ, arrivée), coefficients exacts
        $this->assertSame([['tranche', 'kilo', 0.1], ['unite', 'gramme', 160.0]], array_map(static fn($conversion): array => [$conversion->startUnitSlug, $conversion->endUnitSlug, $conversion->coefficient], $conversions));
        $this->assertSame(2, count(array_unique(array_map(static fn($conversion): string => $conversion->id, $conversions))));
    }

    public function testAnIngredientWithoutAnyConversionOfItsOwnHasNoConversionToRecover(): void
    {
        // When / Then : la conversion commune (kilo <-> gramme) n'est à personne
        $this->assertSame([], $this->usage()->absorbedConversions);
    }

    public function testRecoverConversionsCopiesTheConversionsOnTheKeptIngredientWithTheSameUnitsAndCoefficientAndLeavesTheOriginals(): void
    {
        // Given : deux conversions de « Tomates », dont un coefficient qui ne se représente pas exactement en décimal
        $this->dataset->conversion($this->absorbed, 0.1, new UnitBuilder()->isSlice(), new UnitBuilder()->isKilo());
        $this->dataset->conversion($this->absorbed, 160.0);
        $conversions = $this->usage()->absorbedConversions;
        $newIds = [];
        foreach ($conversions as $index => $conversion) {
            $newIds[$conversion->id] = "copy-$index";
        }
        $otherBefore = $this->dataset->hashOfUser($this->other->getId());

        // When
        $this->repository->recoverConversions($this->absorbed->getSlug(), $this->kept->getSlug(), $newIds);
        $this->entityManager->clear();

        // Then : « Tomate » a ses copies (nouveaux identifiants, mêmes unités, même coefficient au bit près), « Tomates » garde les siennes
        $this->assertSame([['tranche', 'kilo', 0.1], ['unite', 'gramme', 160.0]], $this->dataset->ownConversions($this->kept->getSlug()));
        $this->assertSame($this->dataset->ownConversions($this->absorbed->getSlug()), $this->dataset->ownConversions($this->kept->getSlug()));
        $this->assertSame(['copy-0', 'copy-1'], $this->connection()->fetchFirstColumn('SELECT id FROM conversion WHERE ingredient = ? ORDER BY id', [$this->kept->getSlug()]));
        $this->assertSame(2, $this->dataset->count('conversion', 'ingredient = ?', [$this->absorbed->getSlug()]));
        $this->assertSame(1, $this->dataset->count('conversion', 'ingredient IS NULL'), 'la conversion commune n\'est pas copiée');
        $this->assertSame($otherBefore, $this->dataset->hashOfUser($this->other->getId()));
    }

    public function testRecoverConversionsWithNothingToCopyWritesNothing(): void
    {
        // Given
        $before = $this->dataset->tableHashes();

        // When
        $this->repository->recoverConversions($this->absorbed->getSlug(), $this->kept->getSlug(), []);

        // Then
        $this->assertSame($before, $this->dataset->tableHashes());
    }

    public function testRecoverConversionsRefusesAConversionThatIsNotOneOfTheAbsorbedIngredient(): void
    {
        // Given : la conversion de « Tomate » (le gardé) ne peut pas être « récupérée » de « Tomates » ; une inconnue non plus
        $this->dataset->conversion($this->kept);
        $this->dataset->conversion($this->absorbed, 0.1, new UnitBuilder()->isSlice(), new UnitBuilder()->isKilo());
        $own = $this->usage()->absorbedConversions[0]->id;
        $keptConversion = $this->connection()->fetchOne('SELECT id FROM conversion WHERE ingredient = ?', [$this->kept->getSlug()]);

        foreach ([[$keptConversion => 'copy-1'], [$own => 'copy-2', 'inconnue' => 'copy-3']] as $newIds) {
            try {
                // When
                $this->repository->recoverConversions($this->absorbed->getSlug(), $this->kept->getSlug(), $newIds);
                $this->fail('une conversion qui n\'est pas celle de l\'absorbé ne se copie pas');
            } catch (IngredientException $exception) {
                // Then : conflit de fusion (la transaction du bus annule les copies déjà faites)
                $this->assertSame(['INGREDIENT_MERGE_CONFLICT', 409], [$exception->getMessage(), $exception->getCode()]);
            }
        }
        $this->assertSame(['copy-1' => 0, 'copy-2' => 1], ['copy-1' => $this->dataset->count('conversion', 'id = ?', ['copy-1']), 'copy-2' => $this->dataset->count('conversion', 'id = ?', ['copy-2'])], 'la copie sans suite est annulée par la transaction du bus, pas ici');
    }

    public function testUsageSeesTheSentListsThatUseTheKeptOrTheAbsorbedIngredient(): void
    {
        // Given : deux listes envoyées, l'une utilise l'absorbé, l'autre le gardé ; une troisième envoyée n'utilise ni l'un ni l'autre
        $user = $this->user->getId();
        $this->dataset->shoppingList('sent-b', $user, 'SENT', rows: [['sb', $this->absorbed->getSlug(), 1.0, 'kilo']]);
        $this->dataset->shoppingList('sent-a', $user, 'SENT', items: [['sa', $this->kept->getSlug(), 1.0, 'kilo']]);
        $this->dataset->shoppingList('sent-none', $user, 'SENT', rows: [['sn', 'autre', 1.0, 'kilo']]);
        $this->dataset->shoppingList('active', $user, rows: [['ac', $this->absorbed->getSlug(), 1.0, 'kilo']]);

        // When / Then
        $this->assertSame(2, $this->usage()->sentListCount);
    }

    public function testUsageSeesALineOfAnotherUserOnTheAbsorbedIngredient(): void
    {
        // Given : chaque sorte de ligne héritée d'un autre utilisateur, essayée tour à tour
        $b = $this->absorbed->getSlug();
        $other = $this->other->getId();
        $cases = [
            'une recette' => fn() => $this->dataset->recipe('legacy-recipe', $other, [[$b, 1.0, 'gramme']]),
            'une ligne de liste' => fn() => $this->dataset->shoppingList('legacy-list', $other, rows: [['legacy-row', $b, 1.0, 'kilo']]),
            'un ajout à la main' => fn() => $this->dataset->shoppingList('legacy-items', $other, items: [['legacy-item', $b, 1.0, 'kilo']]),
        ];
        $this->assertFalse($this->usage()->usedByAnotherUser);

        foreach ($cases as $label => $addLine) {
            // When
            $addLine();

            // Then : une seule ligne suffit, et l'on ne compte pas les lignes des autres dans ce qui est à l'utilisateur
            $this->assertTrue($this->usage()->usedByAnotherUser, $label);
            $this->assertSame([0, 0], [$this->usage()->recipeCount, $this->usage()->listCount], $label);
            $this->connection()->executeStatement('DELETE FROM recipe_row WHERE recipe = ?', ['legacy-recipe']);
            $this->connection()->executeStatement('DELETE FROM shopping_list_row WHERE shopping_list = ?', ['legacy-list']);
            $this->connection()->executeStatement('DELETE FROM shopping_list_ingredient WHERE shopping_list = ?', ['legacy-items']);
            $this->connection()->executeStatement('DELETE FROM recipe WHERE id = ?', ['legacy-recipe']);
            $this->connection()->executeStatement('DELETE FROM shopping_list WHERE id IN (?, ?)', ['legacy-list', 'legacy-items']);
        }
        $this->assertFalse($this->usage()->usedByAnotherUser);
    }

    public function testRepointMovesTheRecipeRowsAndBumpsTheVersionOfTheRecipesThatChangedOnly(): void
    {
        // Given : une recette de « Tomates », une qui a les deux, une qui n'a que « Tomate », une recette d'un autre utilisateur qui a « Tomate »
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $this->dataset->recipe('r1', $this->user->getId(), [[$b, 100.0, 'gramme']], version: 3);
        $this->dataset->recipe('r2', $this->user->getId(), [[$a, 1.0, 'kilo'], [$b, 2.0, 'unite']], version: 7);
        $this->dataset->recipe('r3', $this->user->getId(), [[$a, 1.0, 'kilo']], version: 9);
        $this->dataset->recipe('r4', $this->other->getId(), [[$a, 1.0, 'kilo']], version: 2);
        $otherBefore = $this->dataset->hashOfUser($this->other->getId());

        // When
        $result = $this->repoint();

        // Then : la recette qui avait les deux garde ses deux lignes, quantités et unités intactes ; les versions de ces deux recettes montent
        $this->assertSame([['r1-row-0', $a, 100.0, 'gramme']], $this->dataset->recipeRows('r1'));
        $this->assertSame([['r2-row-0', $a, 1.0, 'kilo'], ['r2-row-1', $a, 2.0, 'unite']], $this->dataset->recipeRows('r2'));
        $this->assertSame(['r1' => 4, 'r2' => 8, 'r3' => 9, 'r4' => 2], $this->dataset->recipeVersions());
        $this->assertSame(2, $result->recipes);
        $this->assertSame($otherBefore, $this->dataset->hashOfUser($this->other->getId()));
    }

    public function testRepointMergesTheListRowsAndTicksTheMergedRowOnlyIfBothWere(): void
    {
        // Given : quatre listes qui ont les deux ingrédients, une qui n'a que l'absorbé coché, une qui n'a que le gardé
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $user = $this->user->getId();
        $this->dataset->shoppingList('l-both', $user, rows: [['a1', $a, 2.0, 'kilo', true], ['b1', $b, 1.0, 'kilo', true]]);
        $this->dataset->shoppingList('l-kept', $user, rows: [['a2', $a, 2.0, 'kilo', true], ['b2', $b, 1.0, 'kilo', false]]);
        $this->dataset->shoppingList('l-absorbed', $user, rows: [['a3', $a, 2.0, 'kilo', false], ['b3', $b, 1.0, 'kilo', true]]);
        $this->dataset->shoppingList('l-none', $user, rows: [['a4', $a, 2.5, 'kilo', false], ['b4', $b, 1.25, 'kilo', false]]);
        $this->dataset->shoppingList('l-only-b', $user, rows: [['b5', $b, 1.0, 'kilo', true]]);
        $this->dataset->shoppingList('l-only-a', $user, rows: [['a6', $a, 4.0, 'kilo', true]]);

        // When
        $this->repoint();

        // Then
        $this->assertSame([['a1', $a, 3.0, 'kilo', true]], $this->dataset->listRows('l-both'));
        $this->assertSame([['a2', $a, 3.0, 'kilo', false]], $this->dataset->listRows('l-kept'));
        $this->assertSame([['a3', $a, 3.0, 'kilo', false]], $this->dataset->listRows('l-absorbed'));
        $this->assertSame([['a4', $a, 3.75, 'kilo', false]], $this->dataset->listRows('l-none'));
        // la ligne de l'absorbé devient une ligne du gardé, cochage inchangé ; une liste qui n'a que le gardé ne bouge pas
        $this->assertSame([['b5', $a, 1.0, 'kilo', true]], $this->dataset->listRows('l-only-b'));
        $this->assertSame([['a6', $a, 4.0, 'kilo', true]], $this->dataset->listRows('l-only-a'));
        $this->assertSame(0, $this->dataset->count('shopping_list_row', 'ingredient = ?', [$b]));
        $this->assertSame(['l-absorbed' => 6, 'l-both' => 6, 'l-kept' => 6, 'l-none' => 6, 'l-only-a' => 5, 'l-only-b' => 6], $this->dataset->listVersions());
    }

    public function testRepointMergesTheManualIngredientsAndBumpsAListOnceEvenWithARowAndAnItem(): void
    {
        // Given : une liste avec une ligne ET un ajout à la main de « Tomates », et un ajout de chacun dans une autre
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $user = $this->user->getId();
        $this->dataset->shoppingList('l1', $user, rows: [['b1', $b, 1.0, 'kilo']], items: [['y1', $b, 2.0, 'kilo']], version: 10);
        $this->dataset->shoppingList('l2', $user, items: [['y2a', $a, 1.0, 'kilo'], ['y2b', $b, 3.0, 'kilo']], version: 20);

        // When
        $result = $this->repoint();

        // Then : l'ajout de « Tomates » devient un ajout de « Tomate » ou s'ajoute au sien ; la version de chaque liste ne monte qu'une fois
        $this->assertSame([['y1', $a, 2.0, 'kilo']], $this->dataset->listItems('l1'));
        $this->assertSame([['y2a', $a, 4.0, 'kilo']], $this->dataset->listItems('l2'));
        $this->assertSame(['l1' => 11, 'l2' => 21], $this->dataset->listVersions());
        $this->assertSame(2, $result->shoppingLists);
    }

    public function testRepointConvertsTheListQuantitiesAndRoundsTheCountersUp(): void
    {
        // Given : « Tomate » s'achète au gramme ; « Tomates » au kilo : une ligne de 1,5 kilo, deux ajouts à la main, et une liste qui a déjà la
        // ligne de « Tomate » en gramme ; l'unité de la ligne de « Tomate » est celle qui reçoit la quantité
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $user = $this->user->getId();
        $this->dataset->shoppingList('l1', $user, rows: [['b1', $b, 1.5, 'kilo']], items: [['y1', $b, 2.0, 'kilo'], ['y1bis', $b, 1.0, 'litre']]);
        $this->dataset->shoppingList('l2', $user, rows: [['a2', $a, 500.0, 'gramme', false], ['b2', $b, 0.25, 'kilo', false]]);

        // When
        $this->repoint([new UnitFactor('kilo', 'gramme', 1000.0), new UnitFactor('litre', 'gramme', 1000.0)], 'gramme');

        // Then : 1,5 kilo = 1 500 g ; 2 kilos = 2 000 pas de gramme, 1 litre = 1 000 pas ; 500 g + 250 g
        $this->assertSame([['b1', $a, 1500.0, 'gramme', false]], $this->dataset->listRows('l1'));
        $this->assertSame([['y1', $a, 3000.0, 'gramme']], $this->dataset->listItems('l1'));
        $this->assertSame([['a2', $a, 750.0, 'gramme', false]], $this->dataset->listRows('l2'));
    }

    public function testACounterRoundsUpAndNeverDisappears(): void
    {
        // Given : « Tomates » s'achète au gramme, « Tomate » au kilo (1 g = 0,001 kilo)
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $user = $this->user->getId();
        $this->dataset->shoppingList('l1', $user, items: [['y1', $b, 1.0, 'gramme']]);
        $this->dataset->shoppingList('l2', $user, items: [['y2', $b, 1500.0, 'gramme']]);
        $this->dataset->shoppingList('l3', $user, items: [['y3', $b, 2000.0, 'gramme']]);

        // When
        $this->repoint([new UnitFactor('gramme', 'kilo', 0.001)]);

        // Then : 0,001 kilo donne 1 pas, 1,5 kilo en donne 2, et 2 000 grammes en donnent 2 pile (la tolérance évite un 3 à un flottant près)
        $this->assertSame([['y1', $a, 1.0, 'kilo']], $this->dataset->listItems('l1'));
        $this->assertSame([['y2', $a, 2.0, 'kilo']], $this->dataset->listItems('l2'));
        $this->assertSame([['y3', $a, 2.0, 'kilo']], $this->dataset->listItems('l3'));
    }

    public function testAnItemOfAnotherUnitThanThePurchaseUnitOfTheKeptIngredientIsKeptApart(): void
    {
        // Given : « Tomate » a un ajout en gramme (une ancienne unité d'achat) ; « Tomates » un ajout en kilo (= l'unité d'achat de « Tomate »)
        $a = $this->kept->getSlug();
        $b = $this->absorbed->getSlug();
        $this->dataset->shoppingList('l1', $this->user->getId(), items: [['ya', $a, 2.0, 'gramme'], ['yb', $b, 3.0, 'kilo']]);

        // When
        $this->repoint();

        // Then : les ajouts à la main sont par (liste, ingrédient, unité) : celui de « Tomates » devient un ajout de « Tomate » au kilo, à côté
        $this->assertSame([['ya', $a, 2.0, 'gramme'], ['yb', $a, 3.0, 'kilo']], $this->dataset->listItems('l1'));
    }

    public function testAMissingFactorCancelsTheWriteWithAConflict(): void
    {
        // Given : une ligne en litre apparue entre l'analyse et l'écriture : aucun facteur ne la ré-exprime
        $this->dataset->shoppingList('l1', $this->user->getId(), rows: [['b1', $this->absorbed->getSlug(), 1.0, 'litre']]);

        // Then
        $this->expectExceptionObject(IngredientException::mergeConflict());

        // When
        $this->repoint();
    }

    public function testAReferenceLeftOnTheAbsorbedIngredientCancelsTheWriteWithAConflict(): void
    {
        // Given : la recette d'un autre utilisateur utilise encore l'absorbé (écrite après l'analyse, ou donnée héritée)
        $this->dataset->recipe('legacy', $this->other->getId(), [[$this->absorbed->getSlug(), 1.0, 'gramme']]);

        // Then
        $this->expectExceptionObject(IngredientException::mergeConflict());

        // When
        $this->repoint();
    }

    public function testRepointLeavesEveryOtherUserAndTheirRowsUntouched(): void
    {
        // Given : un autre utilisateur qui a ses propres « Tomate » et « Tomates » et des recettes et listes dessus
        $otherA = $this->dataset->ingredient('Tomate', $this->other);
        $otherB = $this->dataset->ingredient('Tomates', $this->other);
        $this->dataset->recipe('o1', $this->other->getId(), [[$otherB->getSlug(), 1.0, 'gramme']]);
        $this->dataset->shoppingList('ol1', $this->other->getId(), rows: [['ob1', $otherB->getSlug(), 1.0, 'kilo']], items: [['oy1', $otherB->getSlug(), 1.0, 'kilo']]);
        $this->dataset->recipe('r1', $this->user->getId(), [[$this->absorbed->getSlug(), 1.0, 'gramme']]);
        $before = $this->dataset->hashOfUser($this->other->getId());

        // When
        $this->repoint();

        // Then
        $this->assertSame($before, $this->dataset->hashOfUser($this->other->getId()));
        $this->assertSame($otherB->getSlug(), $this->dataset->recipeRows('o1')[0][1]);
        $this->assertNotSame($otherA->getSlug(), $otherB->getSlug());
    }

    public function testLockTakesBothIngredientsInAlphabeticalOrderWithoutFailingOnAnUnknownSlug(): void
    {
        // When / Then : exécutable dans la transaction du test, pour deux ingrédients existants ou non
        $this->repository->lock($this->kept->getSlug(), $this->absorbed->getSlug());
        $this->repository->lock($this->absorbed->getSlug(), 'inconnu');
        $this->addToAssertionCount(1);
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }
}
