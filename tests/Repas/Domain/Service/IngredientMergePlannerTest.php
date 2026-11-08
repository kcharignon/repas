<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\MergeBlock;
use Repas\Repas\Domain\Model\MergeConversion;
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

    private static function absorbed(string $purchaseUnit = 'kilo'): MergeParty
    {
        return new MergeParty('tomates', $purchaseUnit);
    }

    private static function kept(string $purchaseUnit = 'kilo'): MergeParty
    {
        return new MergeParty('tomate', $purchaseUnit);
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
     * @return iterable<string, array{IngredientMergeUsage, MergeBlock}>
     */
    public static function refusalOrder(): iterable
    {
        $missing = ['recipeUnitSlugs' => ['tranche']];

        yield 'une ligne d\'un autre utilisateur passe avant une liste envoyée et une unité qui manque' => [new IngredientMergeUsage(...$missing, sentListCount: 1, usedByAnotherUser: true), MergeBlock::USED_BY_ANOTHER_USER];
        yield 'une liste envoyée passe avant une unité qui manque' => [new IngredientMergeUsage(...$missing, sentListCount: 2), MergeBlock::IN_SENT_LIST];
        yield 'une unité qui manque' => [new IngredientMergeUsage(...$missing), MergeBlock::UNIT_MISSING];
    }

    #[DataProvider('refusalOrder')]
    public function testTheFirstRefusalWinsAndTheMissingUnitsStayKnown(IngredientMergeUsage $usage, MergeBlock $expected): void
    {
        // When
        $plan = $this->planner->plan(self::absorbed(), self::kept(), self::graph(), $usage);

        // Then
        $this->assertSame($expected, $plan->block);
        $this->assertSame(['tranche'], $plan->missingUnitSlugs, 'l\'utilisateur sait tout ce qui l\'attend, même quand un autre refus passe avant');
    }

    public function testTheSameIngredientIsRefusedFirst(): void
    {
        // When : même slug, même si tout le reste est refusable
        $plan = $this->planner->plan(new MergeParty('tomate', 'kilo'), new MergeParty('tomate', 'kilo'), self::graph(), new IngredientMergeUsage(sentListCount: 1, usedByAnotherUser: true));

        // Then
        $this->assertSame(MergeBlock::SAME_INGREDIENT, $plan->block);
    }

    private static function conversion(string $id, string $start, string $end, float $coefficient): MergeConversion
    {
        return new MergeConversion($id, $start, $end, $coefficient);
    }

    /**
     * @param list<MergeConversion> $conversions
     */
    private function planWithConversions(array $conversions, array $recipeUnits = [], array $listMoves = [], ?MergeParty $absorbed = null, ?MergeParty $kept = null): \Repas\Repas\Domain\Model\IngredientMergePlan
    {
        return $this->planner->plan($absorbed ?? self::absorbed(), $kept ?? self::kept(), self::graph(), new IngredientMergeUsage(recipeCount: 1, listCount: 1, recipeUnitSlugs: $recipeUnits, listMoves: $listMoves, absorbedConversions: $conversions));
    }

    public function testAConversionOfTheAbsorbedIngredientThatTheKeptOneLacksIsRecoveredAndLiftsTheRefusal(): void
    {
        // When : une recette de B est en tranches ; A ne sait pas les convertir, B le sait (1 tranche = 25 g)
        $plan = $this->planWithConversions([self::conversion('slice', 'tranche', 'gramme', 25.0)], recipeUnits: ['tranche']);

        // Then : la fusion est possible, la conversion est récupérée, plus aucune unité ne manque
        $this->assertTrue($plan->canMerge());
        $this->assertSame([], $plan->missingUnitSlugs);
        $this->assertEquals([self::conversion('slice', 'tranche', 'gramme', 25.0)], $plan->recoveredConversions);
    }

    public function testAnUnitThatNeitherIngredientCanConvertStaysAnImpossibilityEvenAfterTheRecovery(): void
    {
        // When : B sait convertir la tranche, mais la cuillère reste sans chemin chez les deux
        $plan = $this->planWithConversions([self::conversion('slice', 'tranche', 'gramme', 25.0)], recipeUnits: ['tranche', 'cuillere']);

        // Then : refusée, seule la cuillère manque, et rien n'est récupéré puisque rien ne sera écrit
        $this->assertSame(MergeBlock::UNIT_MISSING, $plan->block);
        $this->assertSame(['cuillere'], $plan->missingUnitSlugs);
        $this->assertSame([], $plan->recoveredConversions);
        $this->assertSame([], $plan->compatibleUnitSlugsToAdd);
    }

    public function testAConversionWhoseUnitsAreAlreadyJoinedByTheKeptIngredientIsNeverCopied(): void
    {
        // When : la même paire avec le même coefficient ; la même paire dans l'autre sens ; une paire jointe par un chemin (unité -> gramme -> kilo)
        $plan = $this->planWithConversions([
            self::conversion('same', 'unite', 'gramme', 150.0),
            self::conversion('reverse', 'gramme', 'kilo', 0.001),
            self::conversion('path', 'unite', 'kilo', 0.15),
        ]);

        // Then : rien à copier
        $this->assertTrue($plan->canMerge());
        $this->assertSame([], $plan->recoveredConversions);
    }

    public function testWhenTheCoefficientsDifferTheKeptIngredientWinsAndNothingIsCopied(): void
    {
        // When : B compte 160 g l'unité, A en compte 150 ; B donne 0,2 kilo l'unité, A en déduit 0,15 par son chemin
        $plan = $this->planWithConversions([self::conversion('a', 'unite', 'gramme', 160.0), self::conversion('b', 'unite', 'kilo', 0.2)], recipeUnits: ['unite'], listMoves: [new MergeUnitMove('unite', 'kilo')]);

        // Then : la conversion de A sert, y compris pour re-exprimer une quantité de liste
        $this->assertSame([], $plan->recoveredConversions);
        $this->assertEqualsWithDelta(0.15, $plan->factors[0]->factor, 1e-12);
    }

    public function testARecoveredConversionAlsoGivesTheFactorOfAListQuantity(): void
    {
        // When : B s'achète au litre (une ligne de liste en litre), A au kilo ; B sait que 1 litre pèse 1 000 g
        $plan = $this->planWithConversions([self::conversion('liter', 'litre', 'gramme', 1000.0)], listMoves: [new MergeUnitMove('litre', 'kilo')], absorbed: self::absorbed('litre'));

        // Then : la quantité de liste est convertie litre -> gramme -> kilo
        $this->assertTrue($plan->canMerge());
        $this->assertTrue($plan->listQuantitiesConverted());
        $this->assertEquals([new UnitFactor('litre', 'kilo', 1.0)], $plan->factors);
        $this->assertCount(1, $plan->recoveredConversions);
    }

    public function testTwoConversionsThatJoinTheSameNewUnitCopyOnlyTheFirstInAFixedOrder(): void
    {
        // Given : deux conversions de B qui relient la tranche à A : par le gramme (25 g) et par le kilo (0,03 kilo), de coefficients incompatibles
        $conversions = [self::conversion('z', 'tranche', 'kilo', 0.03), self::conversion('a', 'tranche', 'gramme', 25.0)];

        // When : lues dans un ordre, puis dans l'autre
        $forward = $this->planWithConversions($conversions, recipeUnits: ['tranche']);
        $backward = $this->planWithConversions(array_reverse($conversions), recipeUnits: ['tranche']);

        // Then : la première dans l'ordre (départ, arrivée, identifiant) est copiée, la seconde est déjà jointe ; jamais deux chemins pour une unité
        $this->assertEquals($forward, $backward);
        $this->assertEquals([self::conversion('a', 'tranche', 'gramme', 25.0)], $forward->recoveredConversions);
    }

    public function testAConversionCanJoinTheUnitsOfAnotherRecoveredOne(): void
    {
        // When : B sait que la tranche vaut une portion, et que la portion vaut 30 g : la portion n'est connue de A que par la seconde
        $plan = $this->planWithConversions([self::conversion('first', 'portion', 'gramme', 30.0), self::conversion('second', 'tranche', 'portion', 0.5)], recipeUnits: ['tranche']);

        // Then : les deux sont copiées (la seconde relie une unité que la première vient d'apporter)
        $this->assertTrue($plan->canMerge());
        $this->assertSame(['first', 'second'], array_map(static fn(MergeConversion $conversion): string => $conversion->id, $plan->recoveredConversions));
    }

    public function testAConversionWithoutAUsableCoefficientIsIgnored(): void
    {
        // When
        $plan = $this->planWithConversions([self::conversion('zero', 'tranche', 'gramme', 0.0), self::conversion('negative', 'portion', 'gramme', -2.0)], recipeUnits: ['tranche']);

        // Then : elle ne relie rien, la tranche manque
        $this->assertSame(MergeBlock::UNIT_MISSING, $plan->block);
        $this->assertSame([], $plan->recoveredConversions);
    }

    public function testNothingIsRecoveredNorAddedWhenAnotherRefusalStopsTheMerge(): void
    {
        // Given : une conversion récupérable et une unité compatible à ajouter, mais une liste envoyée utilise l'un des deux
        $absorbed = new MergeParty('tomates', 'kilo', ['gramme', 'tranche']);
        $usage = new IngredientMergeUsage(recipeCount: 1, recipeUnitSlugs: ['tranche'], sentListCount: 1, absorbedConversions: [self::conversion('slice', 'tranche', 'gramme', 25.0)]);

        // When
        $plan = $this->planner->plan($absorbed, new MergeParty('tomate', 'kilo', ['gramme']), self::graph(), $usage);

        // Then : refusée pour la liste envoyée, rien ne serait écrit (ni conversion, ni unité), et plus aucune unité ne manque
        $this->assertSame(MergeBlock::IN_SENT_LIST, $plan->block);
        $this->assertSame([[], [], []], [$plan->recoveredConversions, $plan->compatibleUnitSlugsToAdd, $plan->missingUnitSlugs]);
    }

    public function testWithoutAnyConversionOnTheAbsorbedIngredientNothingIsRecovered(): void
    {
        // When
        $plan = $this->planWithConversions([], recipeUnits: ['gramme', 'unite']);

        // Then
        $this->assertTrue($plan->canMerge());
        $this->assertSame([], $plan->recoveredConversions);
    }

    public function testTheCompatibleUnitsOfTheAbsorbedIngredientUsedByTheMovedRowsJoinTheKeptOnes(): void
    {
        // Given : B déclare la tranche et la cuillère compatibles ; A le gramme seulement ; les lignes déplacées utilisent la tranche et le gramme
        $absorbed = new MergeParty('tomates', 'kilo', ['gramme', 'tranche', 'cuillere']);
        $kept = new MergeParty('tomate', 'kilo', ['gramme']);

        // When
        $plan = $this->planWithConversions([self::conversion('slice', 'tranche', 'gramme', 25.0)], recipeUnits: ['gramme', 'tranche'], absorbed: $absorbed, kept: $kept);

        // Then : la tranche (utilisée, compatible chez B, absente chez A) s'ajoute ; la cuillère n'est utilisée par aucune ligne ; le gramme y était déjà
        $this->assertSame(['tranche'], $plan->compatibleUnitSlugsToAdd);
    }

    public function testNoCompatibleUnitIsAddedForAUnitThatTheAbsorbedIngredientNeverDeclared(): void
    {
        // When : la ligne utilise le gramme, que B ne déclare pas compatible
        $plan = $this->planWithConversions([], recipeUnits: ['gramme'], absorbed: new MergeParty('tomates', 'kilo', ['kilo']), kept: new MergeParty('tomate', 'kilo', []));

        // Then
        $this->assertSame([], $plan->compatibleUnitSlugsToAdd);
    }

    public function testTheBlocksMapToTheDomainExceptionsOfTheApi(): void
    {
        // Then : message = code en majuscules (ADR 0007 §12), statut selon la grille 422 / 409
        $expected = [
            [MergeBlock::SAME_INGREDIENT, 'CANNOT_MERGE_INTO_ITSELF', 422],
            [MergeBlock::USED_BY_ANOTHER_USER, 'INGREDIENT_USED_BY_ANOTHER_USER', 409],
            [MergeBlock::IN_SENT_LIST, 'INGREDIENT_IN_SENT_LIST', 409],
            [MergeBlock::PART_CONFLICT, 'MERGE_PART_CONFLICT', 422],
            [MergeBlock::PART_IN_LIST, 'MERGE_PART_IN_LIST', 409],
            [MergeBlock::UNIT_MISSING, 'MERGE_UNIT_MISSING', 422],
        ];
        foreach ($expected as [$block, $message, $status]) {
            $exception = $block->toException();
            $this->assertSame([$message, $status], [$exception->getMessage(), $exception->getCode()], $block->name);
        }
        $this->assertCount(count(MergeBlock::cases()), $expected, 'chaque refus a son test');
    }

    public function testThePartRefusalsComeAfterTheSentListAndBeforeTheMissingUnits(): void
    {
        // Then : l'ordre de l'énumération est celui des refus (ADR 0038 décision 25)
        $cases = array_map(static fn(MergeBlock $block): string => $block->name, MergeBlock::cases());
        $this->assertSame(['SAME_INGREDIENT', 'USED_BY_ANOTHER_USER', 'IN_SENT_LIST', 'PART_CONFLICT', 'PART_IN_LIST', 'UNIT_MISSING'], $cases);
    }

    /**
     * @return iterable<string, array{IngredientMergeUsage, MergeBlock}>
     */
    public static function partRefusalOrder(): iterable
    {
        $parts = ['recipeUnitSlugs' => ['tranche']];

        yield 'une liste envoyée passe avant un conflit de parties' => [new IngredientMergeUsage(...$parts, sentListCount: 1, keptPartCount: 11), MergeBlock::IN_SENT_LIST];
        yield 'un conflit de parties passe avant le registre d\'une liste' => [new IngredientMergeUsage(...$parts, keptPartCount: 11, partRegistryMentioned: true), MergeBlock::PART_CONFLICT];
        yield 'le registre d\'une liste passe avant une unité qui manque' => [new IngredientMergeUsage(...$parts, partRegistryMentioned: true), MergeBlock::PART_IN_LIST];
    }

    #[DataProvider('partRefusalOrder')]
    public function testThePartRefusalsAreOrderedBetweenTheSentListAndTheMissingUnits(IngredientMergeUsage $usage, MergeBlock $expected): void
    {
        // When
        $plan = $this->planner->plan(self::absorbed(), self::kept(), self::graph(), $usage);

        // Then
        $this->assertSame($expected, $plan->block);
        $this->assertSame(['tranche'], $plan->missingUnitSlugs, 'l\'utilisateur sait tout ce qui l\'attend');
    }

    private static function party(string $slug, ?string $parent = null, string $purchase = 'unite', string $cooking = 'unite'): MergeParty
    {
        return new MergeParty($slug, $purchase, [], $parent, $cooking);
    }

    /**
     * Chaque combinaison de liens : A est le gardé, B l'absorbé (ADR 0038 décision 25). Le troisième champ dit si un lien est à transporter.
     *
     * @return iterable<string, array{MergeParty, MergeParty, IngredientMergeUsage, ?MergeBlock, bool}>
     */
    public static function partCombinations(): iterable
    {
        yield 'aucun lien' => [self::party('a'), self::party('b'), new IngredientMergeUsage(), null, false];
        yield 'l\'absorbé est une partie, le gardé un ingrédient ordinaire : il en hérite' => [self::party('a'), self::party('b', 'oeuf'), new IngredientMergeUsage(), null, true];
        yield 'le gardé est une partie, l\'absorbé un ingrédient ordinaire : le gardé garde son lien' => [self::party('a', 'oeuf'), self::party('b'), new IngredientMergeUsage(), null, false];
        yield 'deux parties du même parent : rien à transporter' => [self::party('a', 'oeuf'), self::party('b', 'oeuf'), new IngredientMergeUsage(), null, false];
        yield 'deux parties de deux parents différents' => [self::party('a', 'oeuf'), self::party('b', 'citron'), new IngredientMergeUsage(), MergeBlock::PART_CONFLICT, false];
        yield 'le parent absorbe l\'une de ses parties' => [self::party('oeuf'), self::party('jaune', 'oeuf'), new IngredientMergeUsage(keptPartCount: 2), MergeBlock::PART_CONFLICT, false];
        yield 'une partie absorbe son parent' => [self::party('jaune', 'oeuf'), self::party('oeuf'), new IngredientMergeUsage(absorbedPartCount: 2), MergeBlock::PART_CONFLICT, false];
        yield 'le gardé serait sa propre partie même sans décompte' => [self::party('jaune', 'oeuf'), self::party('oeuf'), new IngredientMergeUsage(), MergeBlock::PART_CONFLICT, false];
        yield 'un parent qui a des parties absorbé dans une partie : une chaîne' => [self::party('a', 'oeuf'), self::party('b'), new IngredientMergeUsage(absorbedPartCount: 2), MergeBlock::PART_CONFLICT, false];
        yield 'une partie absorbée dans un parent qui a des parties : une chaîne' => [self::party('a'), self::party('b', 'oeuf'), new IngredientMergeUsage(keptPartCount: 2), MergeBlock::PART_CONFLICT, false];
        yield 'un parent absorbe un autre parent : ses parties deviennent les siennes' => [self::party('a'), self::party('b'), new IngredientMergeUsage(keptPartCount: 2, absorbedPartCount: 3), null, true];
        yield 'un parent absorbe un ingrédient ordinaire : rien à transporter' => [self::party('a'), self::party('b'), new IngredientMergeUsage(keptPartCount: 2), null, false];
        yield 'un ingrédient ordinaire absorbe un parent' => [self::party('a'), self::party('b'), new IngredientMergeUsage(absorbedPartCount: 2), null, true];
        yield 'dix parties en tout : le plafond est atteint, pas dépassé' => [self::party('a'), self::party('b'), new IngredientMergeUsage(keptPartCount: 5, absorbedPartCount: 5), null, true];
        yield 'onze parties en tout : le gardé dépasserait le plafond' => [self::party('a'), self::party('b'), new IngredientMergeUsage(keptPartCount: 6, absorbedPartCount: 5), MergeBlock::PART_CONFLICT, false];
        yield 'le lien hérité ne vaut que pour la même unité d\'achat' => [self::party('a', null, 'boite'), self::party('b', 'oeuf'), new IngredientMergeUsage(), MergeBlock::PART_CONFLICT, false];
        yield 'une unité d\'achat différente est sans importance quand le gardé garde son lien' => [self::party('a', 'oeuf', 'boite'), self::party('b', 'oeuf'), new IngredientMergeUsage(), null, false];
        yield 'les parties ne passent qu\'à un parent qui se cuisine dans la même unité' => [self::party('a', null, 'unite', 'boite'), self::party('b'), new IngredientMergeUsage(absorbedPartCount: 2), MergeBlock::PART_CONFLICT, false];
        yield 'une unité de cuisine différente est sans importance sans partie à transporter' => [self::party('a', null, 'unite', 'boite'), self::party('b'), new IngredientMergeUsage(), null, false];
        yield 'le registre d\'une liste non terminée nomme l\'un des deux' => [self::party('a'), self::party('b'), new IngredientMergeUsage(partRegistryMentioned: true), MergeBlock::PART_IN_LIST, false];
        yield 'le registre refuse aussi deux ingrédients ordinaires' => [self::party('a'), self::party('b'), new IngredientMergeUsage(partRegistryMentioned: true, openListLine: true), MergeBlock::PART_IN_LIST, false];
        yield 'une ligne en cours et un gardé qui serait une partie' => [self::party('a', 'oeuf'), self::party('b'), new IngredientMergeUsage(openListLine: true), MergeBlock::PART_IN_LIST, false];
        yield 'une ligne en cours et un gardé qui hériterait d\'un lien' => [self::party('a'), self::party('b', 'oeuf'), new IngredientMergeUsage(openListLine: true), MergeBlock::PART_IN_LIST, false];
        yield 'une ligne en cours ne gêne pas deux ingrédients sans lien' => [self::party('a'), self::party('b'), new IngredientMergeUsage(openListLine: true), null, false];
        yield 'une ligne en cours ne gêne pas deux parents' => [self::party('a'), self::party('b'), new IngredientMergeUsage(keptPartCount: 1, absorbedPartCount: 1, openListLine: true), null, true];
    }

    #[DataProvider('partCombinations')]
    public function testEachCombinationOfPartLinks(MergeParty $kept, MergeParty $absorbed, IngredientMergeUsage $usage, ?MergeBlock $expected, bool $repointsLinks): void
    {
        // When
        $plan = $this->planner->plan($absorbed, $kept, self::graph(), $usage);

        // Then
        $this->assertSame($expected, $plan->block);
        $this->assertSame($repointsLinks, $plan->repointsPartLinks);
        if ($expected !== null) {
            $this->assertFalse($plan->repointsPartLinks, 'rien n\'est transporté quand la fusion est refusée');
        }
    }

    public function testWithoutAnyPartTheVerdictIsTheOneOfBefore(): void
    {
        // When : un compte sans partie, avec de quoi refuser et de quoi convertir
        $plan = $this->planner->plan(self::absorbed(), self::kept(), self::graph(), new IngredientMergeUsage(recipeCount: 7, listCount: 2, recipeUnitSlugs: ['gramme', 'kilo', 'unite']));

        // Then : possible, et aucune écriture de découpage à prévoir
        $this->assertTrue($plan->canMerge());
        $this->assertFalse($plan->repointsPartLinks);
    }
}
