<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Model\VoiceReplies;
use Repas\Voice\Domain\Service\AlexaDialogue;

/**
 * La conversation d'Alexa avec Repas pour tout ce qui ne demande ni liste ni ingrédient (VOX-204).
 */
class AlexaDialogueTest extends TestCase
{
    private AlexaDialogue $dialogue;

    protected function setUp(): void
    {
        $this->dialogue = new AlexaDialogue();
    }

    public function testAnEndedSessionGetsNoSpeechAndNoQuestionAboutTheAccount(): void
    {
        // When
        $early = $this->dialogue->beforeAuthentication($this->read(AlexaTestRequest::sessionEnded()));

        // Then
        $this->assertEquals(AlexaResponse::silence(), $early);
    }

    public function testAnEndedSessionIsAnsweredWithoutSpeechWhateverTheLanguage(): void
    {
        $this->assertEquals(AlexaResponse::silence(), $this->dialogue->beforeAuthentication($this->read(AlexaTestRequest::sessionEnded()->withLocale('en-US'))));
    }

    #[DataProvider('otherLanguages')]
    public function testAnotherLanguageGetsAPoliteFallback(string $locale): void
    {
        // When
        $early = $this->dialogue->beforeAuthentication($this->read(AlexaTestRequest::addItem()->withLocale($locale)));

        // Then
        $this->assertEquals(AlexaResponse::say(VoiceReplies::UNSUPPORTED_LANGUAGE), $early);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherLanguages(): iterable
    {
        yield 'anglais' => ['en-US'];
        yield 'français du Canada' => ['fr-CA'];
        yield 'absente' => [''];
    }

    public function testFrenchIsServedAndNothingIsAnsweredBeforeAuthentication(): void
    {
        $this->assertNull($this->dialogue->beforeAuthentication($this->read(AlexaTestRequest::addItem())));
        $this->assertNull($this->dialogue->beforeAuthentication($this->read(AlexaTestRequest::launch())));
    }

    public function testLaunchingAsksWhatToAddAndKeepsTheSessionOpen(): void
    {
        $this->assertEquals(AlexaResponse::ask('Que veux-tu ajouter ?', 'Que veux-tu ajouter ?'), $this->dialogue->answer($this->read(AlexaTestRequest::launch())));
    }

    public function testAddingAnItemIsLeftToTheUseCase(): void
    {
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addItem('du pain'))));
    }

    #[DataProvider('slotsNotUnderstood')]
    public function testAnItemThatWasNotUnderstoodIsAskedAgain(?string $said): void
    {
        $this->assertEquals(AlexaResponse::ask(VoiceReplies::LAUNCH, VoiceReplies::LAUNCH), $this->dialogue->answer($this->read(AlexaTestRequest::addItem($said))));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function slotsNotUnderstood(): iterable
    {
        yield 'créneau sans valeur' => [null];
        yield 'vide' => [''];
        yield 'point d\'interrogation' => ['?'];
    }

    public function testAddingAnItemWithAQuantityAndAUnitIsLeftToTheUseCase(): void
    {
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addQuantified('croquettes', 3))));
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addQuantified('tomates', 2, 'kilos', 'kilo'))));
    }

    #[DataProvider('unusableQuantities')]
    public function testAQuantityWeCannotWriteIsAskedAgainWithoutAnything(string|int|float $said): void
    {
        // Then : « Combien ? », la séance reste ouverte (rien n'est écrit : le cas d'usage n'est pas appelé)
        $this->assertEquals(
            AlexaResponse::ask(VoiceReplies::HOW_MANY, VoiceReplies::LAUNCH),
            $this->dialogue->answer($this->read(AlexaTestRequest::addQuantified('croquettes', $said))),
        );
    }

    /**
     * @return iterable<string, array{string|int|float}>
     */
    public static function unusableQuantities(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'cent' => ['100'];
        yield 'négative' => ['-3'];
        yield 'décimale' => ['2.5'];
        yield 'un mot' => ['beaucoup'];
        yield 'énorme' => ['99999999999999999999'];
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer et consulter (ADR 0029 décision 12, VOX-304)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testRemovingAndCheckingAnItemAreLeftToTheirUseCases(): void
    {
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::removeItem('du pain'))));
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::removeItem('croquettes')->withQuantity(3)->withSlot('unit', 'paquets', 'paquet'))));
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::checkItem('des croquettes'))));
    }

    #[DataProvider('itemsNotUnderstood')]
    public function testARemovalOrACheckWithoutAnItemGetsAnExampleNeverAnOpenQuestion(?string $said): void
    {
        // Then : l'exemple, la séance se ferme ; pas de question ouverte (« du pain » dit ensuite serait compris comme un ajout)
        $this->assertEquals(AlexaResponse::say(VoiceReplies::REMOVE_EXAMPLE), $this->dialogue->answer($this->read(AlexaTestRequest::removeItem($said))));
        $this->assertEquals(AlexaResponse::say(VoiceReplies::CHECK_EXAMPLE), $this->dialogue->answer($this->read(AlexaTestRequest::checkItem($said))));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function itemsNotUnderstood(): iterable
    {
        yield 'créneau sans valeur' => [null];
        yield 'point d\'interrogation d\'Alexa' => ['?'];
        yield 'vide' => [''];
    }

    public function testInAnOpenSessionTheExampleLeavesTheSessionOpenWithoutAQuestion(): void
    {
        // Then
        $this->assertEquals(AlexaResponse::stayOpen(VoiceReplies::REMOVE_EXAMPLE), $this->dialogue->answer($this->read(AlexaTestRequest::removeItem(null)->inOpenSession())));
        $this->assertEquals(AlexaResponse::stayOpen(VoiceReplies::CHECK_EXAMPLE), $this->dialogue->answer($this->read(AlexaTestRequest::checkItem(null)->inOpenSession())));
    }

    #[DataProvider('unusableQuantities')]
    public function testAQuantityWeCannotWriteIsAskedAgainWhenRemovingWithoutAnythingBeingWritten(string|int|float $said): void
    {
        // Then : « Combien ? », sans question ouverte : la séance se ferme, rien n'est retiré
        $this->assertEquals(AlexaResponse::say(VoiceReplies::HOW_MANY), $this->dialogue->answer($this->read(AlexaTestRequest::removeItem('croquettes')->withQuantity($said))));
        $this->assertEquals(AlexaResponse::stayOpen(VoiceReplies::HOW_MANY), $this->dialogue->answer($this->read(AlexaTestRequest::removeItem('croquettes')->withQuantity($said)->inOpenSession())));
    }

    public function testWhenAQuestionIsAskedARemovalOrACheckSaidMeanwhileIsLeftToTheDialogue(): void
    {
        // Given : une question du dialogue d'un produit inconnu est posée
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');

        // Then : l'état prime, comme pour tout (la réponse dépend de l'état, pas de l'intention)
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::removeItem('des croquettes')->withSessionAttributes([SpokenDialogueState::ATTRIBUTE => $state->toArray()])->inOpenSession())));
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::checkItem('des croquettes')->withSessionAttributes([SpokenDialogueState::ATTRIBUTE => $state->toArray()])->inOpenSession())));
    }

    public function testAQuantityWithoutAnItemAsksWhatToAdd(): void
    {
        $this->assertEquals(AlexaResponse::ask(VoiceReplies::LAUNCH, VoiceReplies::LAUNCH), $this->dialogue->answer($this->read(AlexaTestRequest::addQuantified('?', 3))));
    }

    public function testHelpGivesAnExampleAndKeepsTheSessionOpen(): void
    {
        $this->assertEquals(AlexaResponse::ask(VoiceReplies::HELP, VoiceReplies::LAUNCH), $this->dialogue->answer($this->read(AlexaTestRequest::intent('AMAZON.HelpIntent'))));
    }

    #[DataProvider('stopIntents')]
    public function testStoppingAndCancellingSayGoodbyeAndEndTheSession(string $intent): void
    {
        $this->assertEquals(AlexaResponse::say('À bientôt.'), $this->dialogue->answer($this->read(AlexaTestRequest::intent($intent))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stopIntents(): iterable
    {
        yield 'stop' => ['AMAZON.StopIntent'];
        yield 'annule' => ['AMAZON.CancelIntent'];
    }

    public function testTheFallbackGivesAnExampleToSay(): void
    {
        $this->assertEquals(AlexaResponse::ask('Dis par exemple : ajoute du pain.', 'Que veux-tu ajouter ?'), $this->dialogue->answer($this->read(AlexaTestRequest::intent('AMAZON.FallbackIntent'))));
    }

    public function testAnIntentOfAmazonTheSkillDoesNotDeclareGetsTheSameFallback(): void
    {
        $this->assertEquals($this->dialogue->answer($this->read(AlexaTestRequest::intent('AMAZON.FallbackIntent'))), $this->dialogue->answer($this->read(AlexaTestRequest::intent('AMAZON.NavigateHomeIntent'))));
        $this->assertEquals($this->dialogue->answer($this->read(AlexaTestRequest::intent('AMAZON.FallbackIntent'))), $this->dialogue->answer($this->read(AlexaTestRequest::intent('N\'importe quoi'))));
    }

    #[DataProvider('otherTypes')]
    public function testAnotherTypeOfRequestGetsTheFallbackAndEndsTheSession(string $type): void
    {
        $this->assertEquals(AlexaResponse::say(VoiceReplies::FALLBACK), $this->dialogue->answer($this->read(AlexaTestRequest::ofType($type))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherTypes(): iterable
    {
        yield 'exception d\'Alexa' => ['System.ExceptionEncountered'];
        yield 'lecteur audio' => ['AudioPlayer.PlaybackStarted'];
        yield 'inconnu' => ['Whatever'];
        yield 'vide' => [''];
    }

    public function testEveryIntentOfTheSkillIsAnsweredOrLeftToTheUseCase(): void
    {
        foreach (AlexaIntent::cases() as $intent) {
            // When
            $answer = $this->dialogue->answer($this->read(AlexaTestRequest::intent($intent->value)));

            // Then : AddItemIntent sans phrase est redemandé, les autres ont leur réponse
            $this->assertInstanceOf(AlexaResponse::class, $answer, $intent->value);
        }
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Le dialogue d'un produit inconnu (ADR 0029 décision 11, VOX-303)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testYesWithoutAQuestionAsksWhatToAdd(): void
    {
        $this->assertEquals(AlexaResponse::ask(VoiceReplies::LAUNCH, VoiceReplies::LAUNCH), $this->dialogue->answer($this->read(AlexaTestRequest::yes())));
    }

    public function testNoWithoutAQuestionIsTheAnswerToAnythingElseAndEndsTheSession(): void
    {
        $this->assertEquals(AlexaResponse::say('À bientôt.'), $this->dialogue->answer($this->read(AlexaTestRequest::no())));
    }

    /**
     * @return iterable<string, array{AlexaTestRequest}>
     */
    public static function dialogueAnswersWithoutADialogue(): iterable
    {
        yield 'un rang' => [AlexaTestRequest::chooseOption('le premier', '1')];
        yield 'un nouveau produit' => [AlexaTestRequest::createItem()];
        yield 'un rayon' => [AlexaTestRequest::answerDepartment('boucherie', 'boucherie')];
        yield 'une unité' => [AlexaTestRequest::answerUnit('kilos', 'kilo')];
        yield 'lesquels' => [AlexaTestRequest::listChoices()];
    }

    #[DataProvider('dialogueAnswersWithoutADialogue')]
    public function testAnAnswerToAQuestionNobodyAskedGetsTheFallback(AlexaTestRequest $request): void
    {
        // Given : séance trop vieille, état perdu, ou Alexa qui devine mal
        $this->assertEquals(AlexaResponse::ask(VoiceReplies::FALLBACK, VoiceReplies::LAUNCH), $this->dialogue->answer($this->read($request->inOpenSession())));
    }

    /**
     * @return iterable<string, array{AlexaTestRequest}>
     */
    public static function everyIntentThatCanAnswerAQuestion(): iterable
    {
        yield 'oui' => [AlexaTestRequest::yes()];
        yield 'non' => [AlexaTestRequest::no()];
        yield 'un rang' => [AlexaTestRequest::chooseOption('le premier', '1')];
        yield 'un nouveau produit' => [AlexaTestRequest::createItem()];
        yield 'un rayon' => [AlexaTestRequest::answerDepartment('boucherie', 'boucherie')];
        yield 'une unité' => [AlexaTestRequest::answerUnit('kilos', 'kilo')];
        yield 'un article' => [AlexaTestRequest::addItem('du pain')];
        yield 'un article incompris' => [AlexaTestRequest::addItem(null)];
        yield 'aide' => [AlexaTestRequest::intent('AMAZON.HelpIntent')];
        yield 'lesquels' => [AlexaTestRequest::listChoices()];
        yield 'repli' => [AlexaTestRequest::intent('AMAZON.FallbackIntent')];
        yield 'intention inconnue' => [AlexaTestRequest::intent('AMAZON.NavigateHomeIntent')];
    }

    #[DataProvider('everyIntentThatCanAnswerAQuestion')]
    public function testWhenAQuestionIsAskedTheAnswerIsLeftToTheUseCaseWhateverTheIntent(AlexaTestRequest $request): void
    {
        // Given : une séance qui porte l'état d'un dialogue
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');

        // Then : l'état décide, pas l'intention
        $this->assertNull($this->dialogue->answer($this->read($request->withSessionAttributes(['dialogue' => $state->toArray()]))));
    }

    #[DataProvider('stopIntents')]
    public function testStopAndCancelCloseTheSessionInTheMiddleOfADialogue(string $intent): void
    {
        // Given
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')->askingDepartment();

        // Then
        $this->assertEquals(
            AlexaResponse::say('À bientôt.'),
            $this->dialogue->answer($this->read(AlexaTestRequest::intent($intent)->withSessionAttributes(['dialogue' => $state->toArray()]))),
        );
    }

    public function testALaunchOfANewSessionIgnoresAStateAndInvites(): void
    {
        // Given : un lancement qui porterait un état (jamais envoyé par Alexa, forgé)
        $body = AlexaTestRequest::launch()->toArray();
        $body['session']['attributes'] = ['dialogue' => SpokenDialogueState::choose('x', [], 'x')->toArray()];

        // Then
        $this->assertEquals(AlexaResponse::ask(VoiceReplies::LAUNCH, VoiceReplies::LAUNCH), $this->dialogue->answer(AlexaRequest::fromDecoded($body)));
    }


    private function read(AlexaTestRequest $request): AlexaRequest
    {
        return AlexaRequest::fromDecoded($request->toArray());
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Planifier un repas (ADR 0029 décision 13, VOX-305)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testAMealWithARecipeIsLeftToTheUseCase(): void
    {
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addMeal('hachis parmentier'))));
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addMeal('hachis parmentier', 'six', '6'))));
    }

    public function testAnItemThatAnnouncesAMealIsLeftToTheUseCaseToo(): void
    {
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addItem('le repas hachis parmentier pour six personnes'))));
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addItem('la recette de gratin'))));
    }

    public function testAnItemWithoutTheWordStaysAProduct(): void
    {
        // When : « ajoute un hachis parmentier » : même phrase de fin, mais rien n'annonce un repas
        $answer = $this->dialogue->answer($this->read(AlexaTestRequest::addItem('un hachis parmentier')));

        // Then : un produit, comme avant (le cas d'usage des produits)
        $this->assertNull($answer);
    }

    #[DataProvider('mealWithoutARecipe')]
    public function testAMealWithoutARecipeGetsAnExampleNeverAnOpenQuestion(AlexaTestRequest $request): void
    {
        // When
        $direct = $this->dialogue->answer($this->read($request));
        $inOpenSession = $this->dialogue->answer($this->read($request->inOpenSession()));

        // Then : « Dis par exemple : planifie le repas hachis parmentier. » (la réponse brève « hachis » serait comprise comme un produit)
        $this->assertEquals(AlexaResponse::say(VoiceReplies::MEAL_EXAMPLE), $direct);
        $this->assertEquals(AlexaResponse::stayOpen(VoiceReplies::MEAL_EXAMPLE), $inOpenSession);
    }

    /**
     * @return iterable<string, array{AlexaTestRequest}>
     */
    public static function mealWithoutARecipe(): iterable
    {
        yield 'planifie sans recette' => [AlexaTestRequest::addMeal(null)];
        yield 'planifie avec seulement le mot' => [AlexaTestRequest::addMeal('le repas')];
        yield 'ajoute le repas' => [AlexaTestRequest::addItem('le repas')];
        yield 'ajoute la recette' => [AlexaTestRequest::addItem('la recette')];
    }

    public function testANumberOfPeopleThatCannotBeWrittenIsAskedAgainAndNothingIsWritten(): void
    {
        // When
        $answer = $this->dialogue->answer($this->read(AlexaTestRequest::addMeal('hachis parmentier', '0')));

        // Then
        $this->assertEquals(AlexaResponse::say(VoiceReplies::HOW_MANY), $answer);
    }

    #[DataProvider('unusableServingsSaidInThePhrase')]
    public function testANumberOfPeopleSaidInThePhraseButOutOfRangeIsAskedAgainAndNothingIsWritten(AlexaTestRequest $request): void
    {
        // When
        $answer = $this->dialogue->answer($this->read($request));

        // Then : « Combien ? Redis ta demande avec un nombre de 1 à 99. » plutôt qu'une recette qui s'appelle « … pour 100 personnes »
        $this->assertEquals(AlexaResponse::say(VoiceReplies::HOW_MANY), $answer);
    }

    /**
     * @return iterable<string, array{AlexaTestRequest}>
     */
    public static function unusableServingsSaidInThePhrase(): iterable
    {
        yield 'pour 100 personnes' => [AlexaTestRequest::addMeal('hachis parmentier pour 100 personnes')];
        yield 'pour 0' => [AlexaTestRequest::addMeal('hachis parmentier pour 0')];
        yield 'pour cent personnes' => [AlexaTestRequest::addMeal('hachis parmentier pour cent personnes')];
        yield 'dit à l\'intention des produits' => [AlexaTestRequest::addItem('le repas hachis parmentier pour 100 personnes')];
    }

    public function testANumberOfPeopleInTheServingsSlotBeatsAnUnusableOneInThePhrase(): void
    {
        $this->assertNull($this->dialogue->answer($this->read(AlexaTestRequest::addMeal('hachis parmentier pour 100 personnes', 'quatre', '4'))));
    }

    public function testTheAnswersOfTheMealDialogueWithoutAnyQuestionInProgressGetTheFallback(): void
    {
        // Given : séance trop vieille, état perdu
        foreach ([AlexaTestRequest::answerServings('six', '6'), AlexaTestRequest::chooseKind('recette', 'meal'), AlexaTestRequest::keepMeal()] as $request) {
            // When
            $answer = $this->dialogue->answer($this->read($request));

            // Then
            $this->assertEquals(AlexaResponse::ask(VoiceReplies::FALLBACK, VoiceReplies::LAUNCH), $answer);
        }
    }

    public function testAMealStateDefersEveryAnswerToTheUseCase(): void
    {
        // Given
        $state = SpokenDialogueState::askingServings(new DialogueOption('recipe-1', 'gratin'), 4);

        // When / Then : l'état décide, quelle que soit l'intention ; seuls « stop » et « annule » sont traités ici
        foreach ([AlexaTestRequest::answerServings('six', '6'), AlexaTestRequest::keepMeal(), AlexaTestRequest::yes(), AlexaTestRequest::no(), AlexaTestRequest::addItem('six')] as $request) {
            $this->assertNull($this->dialogue->answer($this->read($request->withSessionAttributes([SpokenDialogueState::ATTRIBUTE => $state->toArray()]))));
        }
        $this->assertEquals(
            AlexaResponse::say(VoiceReplies::GOODBYE),
            $this->dialogue->answer($this->read(AlexaTestRequest::intent('AMAZON.StopIntent')->withSessionAttributes([SpokenDialogueState::ATTRIBUTE => $state->toArray()]))),
        );
    }
}
