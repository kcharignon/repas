<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\SpokenDialogueState;

/**
 * L'état du dialogue d'un produit inconnu (ADR 0029 décision 11, VOX-303) : ses étapes, sa forme dans les attributs de séance et sa lecture
 * tolérante. La lecture dans une requête entière est prouvée par `AlexaRequestTest`.
 */
class SpokenDialogueStateTest extends TestCase
{
    private static function persil(): DialogueOption
    {
        return new DialogueOption('persil-u', 'persil');
    }

    public function testEachStepKeepsWhatTheDialogueAlreadyKnowsAndForgetsTheMisses(): void
    {
        // Given
        $choose = SpokenDialogueState::choose('de la ciboulette', [self::persil()], 'ciboulette')->missed();

        // When
        $department = $choose->askingDepartment();
        $unit = $department->missed()->withDepartment(new DialogueOption('fruit-et-legume', 'fruit & légume'));
        $confirm = $unit->missed()->withUnit(new DialogueOption('unite', 'unité'));

        // Then : l'étape, le nom et la phrase suivent ; les propositions s'en vont ; le compteur repart à zéro
        $this->assertSame([DialogueStep::CHOOSE, 1, 1], [$choose->step, $choose->misses, count($choose->options)]);
        $this->assertSame([DialogueStep::DEPARTMENT, 0, 0, 'ciboulette', 'de la ciboulette'], [$department->step, $department->misses, count($department->options), $department->name, $department->phrase]);
        $this->assertSame([DialogueStep::UNIT, 0, 'fruit-et-legume'], [$unit->step, $unit->misses, $unit->department?->slug]);
        $this->assertSame([DialogueStep::CONFIRM, 0, 'fruit-et-legume', 'unite'], [$confirm->step, $confirm->misses, $confirm->department?->slug, $confirm->unit?->slug]);
    }

    public function testTwoMissesInTheSameStepMeanTheDialogueIsGivenUp(): void
    {
        // Given
        $state = SpokenDialogueState::choose('x', [], 'x');

        // Then
        $this->assertFalse($state->hasMissedTooMuch());
        $this->assertFalse($state->missed()->hasMissedTooMuch());
        $this->assertTrue($state->missed()->missed()->hasMissedTooMuch());
    }

    public function testThePhraseIsRememberableOnlyWhenProposalsWereMade(): void
    {
        // Given : des propositions faites ; aucune proposition (« Je le crée ? ») ; les étapes suivantes
        $proposed = SpokenDialogueState::choose('des patates', [self::persil()], 'patates');
        $nothingProposed = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette');

        // Then : seule l'étape `choose` AVEC propositions donne une phrase à retenir
        $this->assertSame('des patates', $proposed->rememberablePhrase());
        $this->assertSame('des patates', $proposed->missed()->rememberablePhrase());
        $this->assertNull($nothingProposed->rememberablePhrase());
        $this->assertNull($proposed->askingDepartment()->rememberablePhrase());
        $this->assertNull($proposed->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))->rememberablePhrase());
        $this->assertNull($proposed->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))->withUnit(new DialogueOption('kilo', 'kilo'))->rememberablePhrase());
    }

    private static function tie(): SpokenDialogueState
    {
        return SpokenDialogueState::choose('des tomates', [
            new DialogueOption('tomate-fl', 'tomate', 'fruit-et-legume', 'fruit & légume'),
            new DialogueOption('tomate-sg', 'tomate', 'surgele', 'surgelé'),
        ], 'tomate');
    }

    public function testIngredientsToBeToldApartCarryTheirDepartmentThroughTheSessionAttributes(): void
    {
        // Given
        $tie = self::tie();

        // When
        $readBack = SpokenDialogueState::fromArray(json_decode(json_encode($tie->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        // Then : le rayon voyage, c'est un état « à départager », la phrase d'origine ne se retient pas comme une correction
        $this->assertEquals($tie, $readBack);
        $this->assertSame('surgele', $tie->toArray()['options'][1]['departmentSlug']);
        $this->assertTrue($readBack->isTieBreak());
        $this->assertNull($readBack->rememberablePhrase());
    }

    public function testOrdinaryProposalsAreNotATieBreakAndKeepTheirShape(): void
    {
        $proposals = SpokenDialogueState::choose('de la ciboulette', [self::persil(), new DialogueOption('basilic-u', 'basilic')], 'ciboulette');

        $this->assertFalse($proposals->isTieBreak());
        $this->assertSame(['slug' => 'persil-u', 'name' => 'persil'], $proposals->toArray()['options'][0]);
        $this->assertSame('de la ciboulette', $proposals->rememberablePhrase());
        $this->assertFalse(SpokenDialogueState::choose('x', [], 'x')->isTieBreak());
        $this->assertFalse(SpokenDialogueState::choose('x', [new DialogueOption('a', 'a', 's', 'S')], 'x')->isTieBreak(), 'il en faut au moins deux');
    }

    public function testAnOddDepartmentOnAProposalIsReadAsNoDepartment(): void
    {
        $data = self::tie()->toArray();
        $data['options'][0]['departmentName'] = str_repeat('x', 101);
        $data['options'][1]['departmentSlug'] = "bad\u{0007}";

        $read = SpokenDialogueState::fromArray($data);

        $this->assertNotNull($read);
        $this->assertFalse($read->isTieBreak());
        $this->assertSame(['slug' => 'tomate-fl', 'name' => 'tomate'], $read->toArray()['options'][0]);
        $this->assertSame(['slug' => 'tomate-sg', 'name' => 'tomate'], $read->toArray()['options'][1]);
    }

    public function testChoosingKeepsAtMostThreeOptions(): void
    {
        // When
        $state = SpokenDialogueState::choose('x', array_map(static fn(int $i): DialogueOption => new DialogueOption("s$i", "n$i"), range(1, 5)), 'x');

        // Then
        $this->assertSame(['s1', 's2', 's3'], array_map(static fn(DialogueOption $option): string => $option->slug, $state->options));
    }

    public function testEveryStepSurvivesTheTripThroughTheSessionAttributes(): void
    {
        // Given
        $choose = SpokenDialogueState::choose('de la ciboulette', [self::persil(), new DialogueOption('basilic-u', 'basilic')], 'ciboulette');
        $department = $choose->askingDepartment();
        $unit = $department->withDepartment(new DialogueOption('fruit-et-legume', 'fruit & légume'));
        $confirm = $unit->withUnit(new DialogueOption('unite', 'unité'));

        foreach ([$choose, $choose->missed(), $department, $unit, $unit->missed(), $confirm] as $state) {
            // When : JSON aller-retour, comme le fait Alexa
            $back = SpokenDialogueState::fromArray(json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true));

            // Then
            $this->assertEquals($state, $back, $state->step->value);
        }
    }

    public function testTheQuantityAndTheUnitSaidAtTheStartTravelWithTheDialogue(): void
    {
        // Given : « trois paquets de croquettes », produit inconnu
        $choose = SpokenDialogueState::choose('croquettes', [self::persil()], 'croquettes', 3, 'paquet');
        $states = [$choose, $choose->missed(), $choose->askingDepartment(), $choose->askingDepartment()->withDepartment(new DialogueOption('animal', 'animal')),
            $choose->askingDepartment()->withDepartment(new DialogueOption('animal', 'animal'))->withUnit(new DialogueOption('unite', 'unité'))];

        foreach ($states as $state) {
            // When : à chaque étape, puis après le voyage par les attributs de séance
            $back = SpokenDialogueState::fromArray(json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true));

            // Then
            $this->assertSame([3, 'paquet'], [$state->quantity, $state->spokenUnitSlug], $state->step->value);
            $this->assertEquals($state, $back, $state->step->value);
        }
        $this->assertSame(3, $choose->toArray()['quantity']);
        $this->assertSame('paquet', $choose->toArray()['spokenUnit']);
    }

    public function testAnOrdinaryRequestWritesNeitherQuantityNorUnit(): void
    {
        // Given : l'état d'avant la quantité, le plus petit possible
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette');

        // Then
        $this->assertArrayNotHasKey('quantity', $state->toArray());
        $this->assertArrayNotHasKey('spokenUnit', $state->toArray());
        $this->assertSame([1, null], [$state->quantity, $state->spokenUnitSlug]);
    }

    #[DataProvider('forgedQuantities')]
    public function testAForgedQuantityOrUnitIsNotBelieved(mixed $quantity, mixed $unit, int $expectedQuantity, ?string $expectedUnit): void
    {
        // Given
        $data = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->toArray();
        $data['quantity'] = $quantity;
        $data['spokenUnit'] = $unit;

        // When
        $state = SpokenDialogueState::fromArray($data);

        // Then : une unité d'achat, rien de dit ; l'état reste lisible
        $this->assertNotNull($state);
        $this->assertSame([$expectedQuantity, $expectedUnit], [$state->quantity, $state->spokenUnitSlug]);
    }

    /**
     * @return iterable<string, array{mixed, mixed, int, ?string}>
     */
    public static function forgedQuantities(): iterable
    {
        yield 'valide' => [7, 'kilo', 7, 'kilo'];
        yield 'zéro' => [0, 'kilo', 1, 'kilo'];
        yield 'cent' => [100, 'kilo', 1, 'kilo'];
        yield 'négative' => [-1, null, 1, null];
        yield 'texte' => ['3', 'kilo', 1, 'kilo'];
        yield 'décimale' => [1.5, null, 1, null];
        yield 'tableau' => [[3], ['kilo'], 1, null];
        yield 'unité avec majuscule' => [2, 'Kilo', 2, null];
        yield 'unité avec balise' => [2, '<b>x</b>', 2, null];
        yield 'unité trop longue' => [2, str_repeat('a', 101), 2, null];
        yield 'unité vide' => [2, '', 2, null];
    }

    public function testTheAttributeKeyAndTheShapeAreTheOnesTheDocumentationGives(): void
    {
        // Given
        $state = SpokenDialogueState::choose('de la ciboulette', [self::persil()], 'ciboulette')->askingDepartment()
            ->withDepartment(new DialogueOption('boucherie', 'boucherie'));

        // Then
        $this->assertSame([
            'step' => 'unit',
            'phrase' => 'de la ciboulette',
            'options' => [],
            'name' => 'ciboulette',
            'misses' => 0,
            'departmentSlug' => 'boucherie',
            'departmentName' => 'boucherie',
        ], $state->toArray());
    }

    public function testAStateReadBackKeepsOnlyWhatItsStepNeeds(): void
    {
        // Given : un état « rayon » qui porte aussi une unité (jamais écrite par nous)
        $data = SpokenDialogueState::choose('x', [], 'x')->askingDepartment()->toArray();
        $data['unitSlug'] = 'kilo';
        $data['unitName'] = 'kilo';
        $data['departmentSlug'] = 'boucherie';
        $data['departmentName'] = 'boucherie';

        // When
        $state = SpokenDialogueState::fromArray($data);

        // Then
        $this->assertNull($state?->unit);
        $this->assertNull($state?->department);
    }

    public function testAnythingThatIsNotAnArrayIsNoState(): void
    {
        foreach ([null, 'choose', 3, true, 1.5] as $value) {
            $this->assertNull(SpokenDialogueState::fromArray($value));
        }
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // L'action du dialogue : ajouter (par défaut) ou retirer (ADR 0029 décision 12)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    private static function removalOptions(): array
    {
        return [
            new DialogueOption('tomate-fl', 'tomate', 'fruit-et-legume', 'fruit et légume'),
            new DialogueOption('tomate-sg', 'tomate', 'surgele', 'surgelé'),
        ];
    }

    public function testAnOrdinaryStateIsAnAddAndWritesNoAction(): void
    {
        // Given
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette');

        // Then : la forme d'un ajout ne change pas
        $this->assertFalse($state->isRemoval());
        $this->assertArrayNotHasKey('action', $state->toArray());
        $this->assertFalse(SpokenDialogueState::fromArray($state->toArray())?->isRemoval());
    }

    public function testARemovalStateWritesItsActionAndSurvivesTheTripWithItsQuantityAndUnit(): void
    {
        // Given : « enlève deux kilos de tomates » avec deux lignes qui se valent
        $state = SpokenDialogueState::chooseToRemove('des tomates', self::removalOptions(), 'tomate', 2, 'kilo');

        // When
        $data = $state->toArray();
        $back = SpokenDialogueState::fromArray($data);

        // Then
        $this->assertTrue($state->isRemoval());
        $this->assertTrue($state->isTieBreak());
        $this->assertNull($state->rememberablePhrase());
        $this->assertSame('remove', $data['action']);
        $this->assertEquals($state, $back);
        $this->assertSame([true, 2, 'kilo'], [$back?->isRemoval(), $back?->quantity, $back?->spokenUnitSlug]);
    }

    public function testTheActionSurvivesAMiss(): void
    {
        // Given
        $state = SpokenDialogueState::chooseToRemove('des tomates', self::removalOptions(), 'tomate');

        // When / Then
        $this->assertTrue($state->missed()->isRemoval());
        $this->assertSame(1, $state->missed()->misses);
    }

    public function testAnUnknownActionIsReadAsAnAdd(): void
    {
        // Given
        $data = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->toArray();
        $data['action'] = 'supprimer-tout';

        // When
        $state = SpokenDialogueState::fromArray($data);

        // Then
        $this->assertNotNull($state);
        $this->assertFalse($state->isRemoval());
    }

    public function testAForgedRemovalThatIsNotATieBreakIsNotBelieved(): void
    {
        // Given : un état « retirer » sans deux lignes qui se valent avec leur rayon (propositions simples, ou étape du rayon)
        $plain = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil', 'persil')], 'ciboulette')->toArray();
        $plain['action'] = 'remove';
        $department = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->askingDepartment()->toArray();
        $department['action'] = 'remove';
        $single = SpokenDialogueState::chooseToRemove('des tomates', [new DialogueOption('tomate-fl', 'tomate', 'fruit-et-legume', 'fruit et légume')], 'tomate')->toArray();

        // Then : jamais d'état qui prétend retirer autre chose qu'un départage
        $this->assertNull(SpokenDialogueState::fromArray($plain));
        $this->assertNull(SpokenDialogueState::fromArray($department));
        $this->assertNull(SpokenDialogueState::fromArray($single));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un repas (ADR 0029 décision 13, VOX-305)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testAnOrdinaryStateWritesNeitherTargetNorSidesNorServings(): void
    {
        // When
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');

        // Then : la forme d'un produit ne change pas (la séance a une taille limite)
        $this->assertSame(['step', 'phrase', 'options', 'name', 'misses'], array_keys($state->toArray()));
        $this->assertFalse($state->isMeal());
        $this->assertFalse($state->otherSideTried);
    }

    public function testAMealStateWritesItsTargetTheSideAlreadyTriedAndTheServingsSaid(): void
    {
        // Given
        $state = SpokenDialogueState::chooseRecipe('gratin', [new DialogueOption('recipe-1', 'gratin dauphinois'), new DialogueOption('recipe-2', 'gratin de pâtes')], 'gratin', 6, true);

        // When
        $data = $state->toArray();

        // Then
        $this->assertSame('choose', $data['step']);
        $this->assertSame('meal', $data['target']);
        $this->assertTrue($data['otherSideTried']);
        $this->assertSame(6, $data['servings']);
        $this->assertSame([['slug' => 'recipe-1', 'name' => 'gratin dauphinois'], ['slug' => 'recipe-2', 'name' => 'gratin de pâtes']], $data['options']);
        $this->assertTrue($state->isMeal());
        $this->assertFalse($state->isTieBreak(), 'les recettes se départagent par le nom, jamais par un rayon');
    }

    public function testAMealStateSurvivesTheTripThroughTheSessionAttributes(): void
    {
        // Given
        $states = [
            SpokenDialogueState::chooseRecipe('gratin', [new DialogueOption('recipe-1', 'gratin dauphinois')], 'gratin'),
            SpokenDialogueState::chooseRecipe('gratin', [], 'gratin', 4, false),
            SpokenDialogueState::chooseRecipe('gratin', [new DialogueOption('recipe-1', 'gratin dauphinois')], 'gratin', null, true)->missed(),
            SpokenDialogueState::askingServings(new DialogueOption('recipe-1', 'gratin dauphinois'), 4),
        ];

        foreach ($states as $state) {
            // When
            $back = SpokenDialogueState::fromArray(json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

            // Then
            $this->assertEquals($state, $back);
        }
    }

    public function testTheAlreadyPlannedStateKeepsTheRecipeAndItsServings(): void
    {
        // When
        $state = SpokenDialogueState::askingServings(new DialogueOption('recipe-1', 'gratin dauphinois'), 4);

        // Then
        $this->assertSame(DialogueStep::SERVINGS, $state->step);
        $this->assertTrue($state->isMeal());
        $this->assertTrue($state->otherSideTried, 'plus d\'aiguillage une fois la recette trouvée');
        $this->assertSame(4, $state->plannedServings);
        $this->assertSame('recipe-1', $state->options[0]->slug);
        $this->assertNull($state->rememberablePhrase(), 'rien à retenir à cette étape');
        $this->assertFalse($state->hasMissedTooMuch());
        $this->assertTrue($state->missed()->missed()->hasMissedTooMuch());
    }

    public function testAVeryLongRecipeNameStillMakesAnAlreadyPlannedStateThatCanBeReadBack(): void
    {
        // Given : un nom de recette de 255 caractères
        $state = SpokenDialogueState::askingServings(new DialogueOption('recipe-1', str_repeat('é', 255)), 4);

        // When
        $back = SpokenDialogueState::fromArray($state->toArray());

        // Then : la phrase et le nom de l'état sont coupés, l'état revient quand même
        $this->assertNotNull($back);
        $this->assertSame(100, mb_strlen($back->name));
    }

    #[DataProvider('forgedMealStates')]
    public function testAForgedMealStateIsNotBelieved(array $data): void
    {
        $this->assertNull(SpokenDialogueState::fromArray($data));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function forgedMealStates(): iterable
    {
        $base = ['phrase' => 'gratin', 'name' => 'gratin', 'misses' => 0];
        $recipe = [['slug' => 'recipe-1', 'name' => 'gratin']];

        yield 'le nombre de personnes sans la recette' => [[...$base, 'step' => 'servings', 'target' => 'meal', 'options' => [], 'plannedServings' => 4]];
        yield 'la recette sans le nombre de personnes' => [[...$base, 'step' => 'servings', 'target' => 'meal', 'options' => $recipe]];
        yield 'le nombre de personnes d\'un produit' => [[...$base, 'step' => 'servings', 'options' => $recipe, 'plannedServings' => 4]];
        yield 'deux recettes pour le nombre de personnes' => [[...$base, 'step' => 'servings', 'target' => 'meal', 'options' => [...$recipe, ...$recipe], 'plannedServings' => 4]];
        yield 'zéro personne' => [[...$base, 'step' => 'servings', 'target' => 'meal', 'options' => $recipe, 'plannedServings' => 0]];
        yield 'cent personnes' => [[...$base, 'step' => 'servings', 'target' => 'meal', 'options' => $recipe, 'plannedServings' => 100]];
        yield 'créer une recette (le rayon)' => [[...$base, 'step' => 'department', 'target' => 'meal', 'options' => []]];
        yield 'créer une recette (l\'unité)' => [[...$base, 'step' => 'unit', 'target' => 'meal', 'options' => [], 'departmentSlug' => 'd', 'departmentName' => 'd']];
        yield 'créer une recette (confirmer)' => [[...$base, 'step' => 'confirm', 'target' => 'meal', 'options' => [], 'departmentSlug' => 'd', 'departmentName' => 'd', 'unitSlug' => 'u', 'unitName' => 'u']];
        yield 'retirer un repas (VOX-306)' => [[...$base, 'step' => 'choose', 'target' => 'meal', 'action' => 'remove', 'options' => $recipe]];
    }

    public function testAForgedServingsOrTargetOrSideIsReadAsNothing(): void
    {
        // When : un nombre de personnes absurde, une cible inconnue, une indication qui n'est pas un booléen
        $state = SpokenDialogueState::fromArray(['step' => 'choose', 'phrase' => 'gratin', 'name' => 'gratin', 'misses' => 0, 'options' => [], 'target' => 'bateau', 'servings' => 500, 'otherSideTried' => 'oui']);

        // Then : un produit, rien de dit, l'autre côté pas essayé
        $this->assertNotNull($state);
        $this->assertFalse($state->isMeal());
        $this->assertNull($state->servings);
        $this->assertFalse($state->otherSideTried);

        // Et un nombre de personnes dit à un produit n'est pas gardé
        $item = SpokenDialogueState::fromArray(['step' => 'choose', 'phrase' => 'gratin', 'name' => 'gratin', 'misses' => 0, 'options' => [], 'servings' => 4]);
        $this->assertNull($item?->servings);
        $meal = SpokenDialogueState::fromArray(['step' => 'choose', 'phrase' => 'gratin', 'name' => 'gratin', 'misses' => 0, 'options' => [], 'target' => 'meal', 'servings' => 4]);
        $this->assertSame(4, $meal?->servings);
    }

    public function testTheMealStateKeepsItsSideAndServingsWhenTheAnswerIsMissed(): void
    {
        // Given
        $state = SpokenDialogueState::chooseRecipe('gratin', [], 'gratin', 6, true);

        // When
        $missed = $state->missed();

        // Then
        $this->assertSame([1, 6, true, true], [$missed->misses, $missed->servings, $missed->otherSideTried, $missed->isMeal()]);
    }

    public function testTheProductStateKeepsTheSideAlreadyTriedThroughItsSteps(): void
    {
        // Given : le produit cherché après « un produit » dit à une recette inconnue
        $state = SpokenDialogueState::choose('gratin', [], 'gratin', otherSideTried: true);

        // When
        $confirm = $state->askingDepartment()->withDepartment(new DialogueOption('d', 'd'))->withUnit(new DialogueOption('u', 'u'));

        // Then
        $this->assertTrue($confirm->otherSideTried);
        $this->assertEquals($confirm, SpokenDialogueState::fromArray($confirm->toArray()));
    }

    public function testTheOriginalPhraseOfARecipeQuestionTravelsOnlyWhenItDiffersFromThePhrase(): void
    {
        // Given : « le repas pour chien » dit pour un produit : la recette cherchée est « pour chien », la phrase d'origine garde le mot
        $state = SpokenDialogueState::chooseRecipe('pour chien', [], 'pour chien', null, false, 'le repas pour chien');
        $same = SpokenDialogueState::chooseRecipe('gratin', [], 'gratin', null, false, 'gratin');

        // Then : écrite seulement quand elle diffère (la séance a une taille limite), et elle revient
        $this->assertSame('le repas pour chien', $state->toArray()['originalPhrase']);
        $this->assertArrayNotHasKey('originalPhrase', $same->toArray());
        $this->assertEquals($state, SpokenDialogueState::fromArray(json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)));
        $this->assertSame('le repas pour chien', $state->originalPhrase);
        $this->assertNull($same->originalPhrase);
    }

    public function testTheOriginalPhraseSurvivesAMiss(): void
    {
        $state = SpokenDialogueState::chooseRecipe('pour chien', [], 'pour chien', null, false, 'le repas pour chien')->missed();

        $this->assertSame(['le repas pour chien', 1], [$state->originalPhrase, $state->misses]);
    }

    public function testAForgedOriginalPhraseIsBoundedAndNeverBelievedOutsideARecipeQuestion(): void
    {
        $base = ['phrase' => 'pour chien', 'name' => 'pour chien', 'misses' => 0, 'options' => [], 'step' => 'choose'];

        // Trop longue, avec un caractère de contrôle, pas une chaîne, vide : ignorée, l'état reste lisible
        foreach ([str_repeat('a', 101), "le repas\u{0007}", 12, '', ['x']] as $forged) {
            $state = SpokenDialogueState::fromArray([...$base, 'target' => 'meal', 'originalPhrase' => $forged]);
            $this->assertNotNull($state);
            $this->assertNull($state->originalPhrase);
        }
        // Seule une question de recette la porte : un produit, ou l'étape du nombre de personnes, l'ignorent
        $this->assertNull(SpokenDialogueState::fromArray([...$base, 'originalPhrase' => 'le repas pour chien'])?->originalPhrase);
        $servings = SpokenDialogueState::fromArray(['step' => 'servings', 'phrase' => 'gratin', 'name' => 'gratin', 'misses' => 0, 'target' => 'meal', 'options' => [['slug' => 'r1', 'name' => 'gratin']], 'plannedServings' => 4, 'originalPhrase' => 'le repas gratin']);
        $this->assertNotNull($servings);
        $this->assertNull($servings->originalPhrase);
        // Une phrase d'origine de cent caractères tient
        $this->assertSame(str_repeat('a', 100), SpokenDialogueState::fromArray([...$base, 'target' => 'meal', 'originalPhrase' => str_repeat('a', 100)])?->originalPhrase);
    }
}
