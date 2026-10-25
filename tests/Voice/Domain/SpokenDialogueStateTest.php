<?php

namespace Repas\Tests\Voice\Domain;


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
