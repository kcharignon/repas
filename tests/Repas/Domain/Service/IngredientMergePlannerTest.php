<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\MergeBlock;
use Repas\Repas\Domain\Model\MergeParty;
use Repas\Repas\Domain\Model\MergeUnitMove;
use Repas\Repas\Domain\Model\UnitFactor;
use Repas\Repas\Domain\Service\ConversionGraph;
use Repas\Repas\Domain\Service\IngredientMergePlanner;

/**
 * Le verdict et les unités de la fusion (`docs/ingredients/fusion.md` §§ 2 et 5), en table : unité compatible, unité qui manque, unité
 * d'achat différente, et l'ordre des refus.
 */
class IngredientMergePlannerTest extends TestCase
{
    private IngredientMergePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new IngredientMergePlanner();
    }

    private static function absorbed(string $purchaseUnit = 'kilo', bool $common = false): MergeParty
    {
        return new MergeParty('tomates', $common, $purchaseUnit);
    }

    private static function kept(string $purchaseUnit = 'kilo', bool $common = false): MergeParty
    {
        return new MergeParty('tomate', $common, $purchaseUnit);
    }

    /** Le graphe de « Tomate » : kilo <-> gramme (1000), unité <-> gramme (150). */
    private static function graph(): ConversionGraph
    {
        return ConversionGraph::fromConversions([['kilo', 'gramme', 1000.0], ['unite', 'gramme', 150.0]]);
    }

    public function testAMergeWithNothingToConvertIsPossible(): void
    {
        // When
        $plan = $this->planner->plan(self::absorbed(), self::kept(), self::graph(), new IngredientMergeUsage(
            recipeCount: 7,
            listCount: 2,
            recipeUnitSlugs: ['gramme', 'kilo', 'unite'],
            listMoves: [new MergeUnitMove('kilo', 'kilo')],
        ));

        // Then : les unités de recette se convertissent toutes en kilo, la liste garde son unité (identité : pas de facteur)
        $this->assertTrue($plan->canMerge());
        $this->assertNull($plan->block);
        $this->assertSame([], $plan->missingUnitSlugs);
        $this->assertSame([], $plan->factors);
        $this->assertFalse($plan->listQuantitiesConverted());
    }

    public function testADifferentPurchaseUnitGivesTheFactorOfTheListQuantities(): void
    {
        // When : B s'achète au kilo, A au gramme ; une ligne de liste est en kilo, un ajout à la main aussi
        $plan = $this->planner->plan(self::absorbed('kilo'), self::kept('gramme'), self::graph(), new IngredientMergeUsage(
            recipeCount: 1,
            listCount: 1,
            recipeUnitSlugs: ['gramme', 'unite'],
            listMoves: [new MergeUnitMove('kilo', 'gramme')],
        ));

        // Then
        $this->assertTrue($plan->canMerge());
        $this->assertTrue($plan->listQuantitiesConverted());
        $this->assertEquals([new UnitFactor('kilo', 'gramme', 1000.0)], $plan->factors);
    }

    public function testAnUnitOfTheRecipesThatTheKeptIngredientCannotConvertStopsTheMerge(): void
    {
        // When : une recette de B utilise des tranches, A n'a aucune conversion pour elles
        $plan = $this->planner->plan(self::absorbed(), self::kept(), self::graph(), new IngredientMergeUsage(recipeCount: 1, recipeUnitSlugs: ['gramme', 'tranche']));

        // Then
        $this->assertFalse($plan->canMerge());
        $this->assertSame(MergeBlock::UNIT_MISSING, $plan->block);
        $this->assertSame(['tranche'], $plan->missingUnitSlugs);
    }

    public function testAnUnitOfTheListsThatTheKeptIngredientCannotConvertStopsTheMerge(): void
    {
        // When : la ligne de liste de B est en litre, A ne sait pas passer du litre au kilo
        $plan = $this->planner->plan(self::absorbed('litre'), self::kept('kilo'), self::graph(), new IngredientMergeUsage(
            listCount: 1,
            listMoves: [new MergeUnitMove('litre', 'kilo')],
        ));

        // Then
        $this->assertSame(MergeBlock::UNIT_MISSING, $plan->block);
        $this->assertSame(['litre'], $plan->missingUnitSlugs);
        $this->assertSame([], $plan->factors);
    }

    public function testEveryMissingUnitIsListedOnceAndSorted(): void
    {
        // When
        $plan = $this->planner->plan(self::absorbed(), self::kept(), self::graph(), new IngredientMergeUsage(
            recipeUnitSlugs: ['tranche', 'gramme', 'cuillere'],
            listMoves: [new MergeUnitMove('tranche', 'kilo'), new MergeUnitMove('kilo', 'kilo')],
        ));

        // Then
        $this->assertSame(['cuillere', 'tranche'], $plan->missingUnitSlugs);
    }

    /**
     * @return iterable<string, array{IngredientMergeUsage, bool, MergeBlock}>
     */
    public static function refusalOrder(): iterable
    {
        $missing = ['recipeUnitSlugs' => ['tranche']];

        yield 'une ligne d\'un autre utilisateur passe avant une liste envoyée et une unité qui manque' => [new IngredientMergeUsage(...$missing, sentListCount: 1, usedByAnotherUser: true), false, MergeBlock::USED_BY_ANOTHER_USER];
        yield 'un choix du drive d\'un autre utilisateur compte comme une ligne d\'un autre' => [new IngredientMergeUsage(...$missing, sentListCount: 1), true, MergeBlock::USED_BY_ANOTHER_USER];
        yield 'une liste envoyée passe avant une unité qui manque' => [new IngredientMergeUsage(...$missing, sentListCount: 2), false, MergeBlock::IN_SENT_LIST];
        yield 'une unité qui manque' => [new IngredientMergeUsage(...$missing), false, MergeBlock::UNIT_MISSING];
    }

    #[DataProvider('refusalOrder')]
    public function testTheFirstRefusalWinsAndTheMissingUnitsStayKnown(IngredientMergeUsage $usage, bool $chosenByAnotherUser, MergeBlock $expected): void
    {
        // When
        $plan = $this->planner->plan(self::absorbed(), self::kept(), self::graph(), $usage, $chosenByAnotherUser);

        // Then
        $this->assertSame($expected, $plan->block);
        $this->assertSame(['tranche'], $plan->missingUnitSlugs, 'l\'utilisateur sait tout ce qui l\'attend, même quand un autre refus passe avant');
    }

    public function testTheSameIngredientIsRefusedFirst(): void
    {
        // When : même slug, même si l'un est commun et que tout le reste est refusable
        $plan = $this->planner->plan(new MergeParty('tomate', true, 'kilo'), new MergeParty('tomate', true, 'kilo'), self::graph(), new IngredientMergeUsage(sentListCount: 1, usedByAnotherUser: true));

        // Then
        $this->assertSame(MergeBlock::SAME_INGREDIENT, $plan->block);
    }

    public function testACommonIngredientIsRefusedWhicheverSideItIsOn(): void
    {
        foreach ([[self::absorbed('kilo', true), self::kept()], [self::absorbed(), self::kept('kilo', true)]] as [$absorbed, $kept]) {
            // When
            $plan = $this->planner->plan($absorbed, $kept, self::graph(), new IngredientMergeUsage(sentListCount: 1));

            // Then : avant la liste envoyée et les unités, que l'utilisateur pourrait lever
            $this->assertSame(MergeBlock::COMMON_INGREDIENT, $plan->block);
        }
    }

    public function testTheBlocksMapToTheDomainExceptionsOfTheApi(): void
    {
        // Then : message = code en majuscules (ADR 0007 §12), statut selon la grille 422 / 409
        $expected = [
            [MergeBlock::SAME_INGREDIENT, 'CANNOT_MERGE_INTO_ITSELF', 422],
            [MergeBlock::COMMON_INGREDIENT, 'CANNOT_MERGE_COMMON_INGREDIENT', 422],
            [MergeBlock::USED_BY_ANOTHER_USER, 'INGREDIENT_USED_BY_ANOTHER_USER', 409],
            [MergeBlock::IN_SENT_LIST, 'INGREDIENT_IN_SENT_LIST', 409],
            [MergeBlock::UNIT_MISSING, 'MERGE_UNIT_MISSING', 422],
        ];
        foreach ($expected as [$block, $message, $status]) {
            $exception = $block->toException();
            $this->assertSame([$message, $status], [$exception->getMessage(), $exception->getCode()], $block->name);
        }
        $this->assertCount(count(MergeBlock::cases()), $expected, 'chaque refus a son test');
    }
}
