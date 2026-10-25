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
}
