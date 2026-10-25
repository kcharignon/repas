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
}
