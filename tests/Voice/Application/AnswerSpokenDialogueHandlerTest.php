<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\SpokenIngredientCreatorInMemory;
use Repas\Tests\Helper\SpokenIngredientResolverInMemory;
use Repas\Tests\Helper\VoiceShoppingListInMemory;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemHandler;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueCommand;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueHandler;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Service\SpokenAnswerInterpreter;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Les réponses aux questions d'un produit inconnu (ADR 0029 décision 11, VOX-303), avec les ports de `Shared` en mémoire : aucune base, aucun
 * noyau. Les flux complets : proposition choisie puis retenue, création guidée, refus, incompréhension. L'interprétation de chaque réponse est
 * prouvée par `SpokenAnswerInterpreterTest`, la création et la mémoire par les tests de `Repas`, le tout câblé par `AlexaWebhookApiFlowTest`.
 */
class AnswerSpokenDialogueHandlerTest extends TestCase
{
    private const string USER = 'user-1';

    private SpokenIngredientResolverInMemory $resolver;
    private VoiceShoppingListInMemory $shoppingList;
    private SpokenIngredientCreatorInMemory $creator;
    private AnswerSpokenDialogueHandler $handler;

    protected function setUp(): void
    {
        $this->resolver = new SpokenIngredientResolverInMemory();
        $this->shoppingList = new VoiceShoppingListInMemory();
        $this->creator = new SpokenIngredientCreatorInMemory();
        $this->handler = new AnswerSpokenDialogueHandler(
            new SpokenAnswerInterpreter(),
            $this->creator,
            $this->resolver,
            $this->shoppingList,
            new AddSpokenItemHandler($this->resolver, $this->shoppingList, new SpokenIngredientNamer()),
        );
    }

    /**
     * @param list<string> $optionNames
     */
    private static function choose(array $optionNames): SpokenDialogueState
    {
        return SpokenDialogueState::choose(
            'de la ciboulette',
            array_map(static fn(string $name): DialogueOption => new DialogueOption($name . '-u', $name), $optionNames),
            'ciboulette',
        );
    }

    private function answer(AlexaTestRequest $request, SpokenDialogueState $state): AddSpokenItemResult
    {
        return ($this->handler)(new AnswerSpokenDialogueCommand(self::USER, AlexaRequest::fromDecoded($request->inOpenSession()->toArray()), $state));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Une proposition est choisie : ajoutée ET retenue
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testChoosingAProposalAddsItAndRemembersThePhrase(): void
    {
        // When : « le deuxième »
        $result = $this->answer(AlexaTestRequest::chooseOption('le deuxième', '2'), self::choose(['persil', 'basilic', 'coriandre']));

        // Then : l'article est ajouté, la phrase entendue est retenue pour l'ingrédient, le dialogue est fini et la séance reste ouverte
        $this->assertSame([[self::USER, 'basilic-u']], $this->shoppingList->added);
        $this->assertSame([[self::USER, 'de la ciboulette', 'basilic-u']], $this->resolver->remembered);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : basilic. Je m'en souviendrai. Autre chose ?", keepSessionOpen: true, added: true), $result);
    }

    public function testSayingTheNameOfAProposalWorksToo(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::addItem('du persil'), self::choose(['persil', 'basilic']));

        // Then
        $this->assertSame([[self::USER, 'persil-u']], $this->shoppingList->added);
        $this->assertSame("C'est noté : persil. Je m'en souviendrai. Autre chose ?", $result->speech);
    }

    public function testYesWithASingleProposalChoosesIt(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::yes(), self::choose(['persil']));

        // Then
        $this->assertSame([[self::USER, 'persil-u']], $this->shoppingList->added);
        $this->assertSame([[self::USER, 'de la ciboulette', 'persil-u']], $this->resolver->remembered);
        $this->assertNull($result->nextState);
    }

    public function testANewListIsSaidAndThePromiseOfMemoryGivesWayToTheSentListWhenThereIsOne(): void
    {
        // Given
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: false));
        $started = $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $sent = $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));

        // Then : la phrase est retenue dans les deux cas
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Je m'en souviendrai. Autre chose ?", $started->speech);
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Ta liste précédente est partie au drive. Autre chose ?", $sent->speech);
        $this->assertCount(2, $this->resolver->remembered);
    }

    public function testAnotherArticleAbandonsTheDialogueAndIsHandledAsANewRequest(): void
    {
        // Given : du pain existe
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose(['persil', 'basilic']));

        // Then : ajouté comme n'importe quelle demande, rien n'est retenu pour la phrase abandonnée
        $this->assertSame([[self::USER, 'pain-u']], $this->shoppingList->added);
        $this->assertSame([], $this->resolver->remembered);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pain. Autre chose ?", keepSessionOpen: true, added: true), $result);
    }

    public function testAnotherUnknownArticleStartsANewDialogue(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::addItem('de la menthe'), self::choose(['persil', 'basilic']));

        // Then
        $this->assertSame('Je ne connais pas menthe. Je le crée ?', $result->speech);
        $this->assertSame(['de la menthe', 'menthe', DialogueStep::CHOOSE], [$result->nextState?->phrase, $result->nextState?->name, $result->nextState?->step]);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Création guidée : rayon, unité, confirmation
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testTheWholeCreationFromTheProposalsToTheShoppingList(): void
    {
        // When : « un nouveau produit » -> « les fruits et légumes » -> « des pièces » -> « oui »
        $department = $this->answer(AlexaTestRequest::createItem(), self::choose(['persil', 'basilic']));
        $unit = $this->answer(AlexaTestRequest::answerDepartment('fruits et légumes', 'fruit-et-legume'), $department->nextState);
        $confirm = $this->answer(AlexaTestRequest::answerUnit('pièces', 'unite'), $unit->nextState);
        $created = $this->answer(AlexaTestRequest::yes(), $confirm->nextState);

        // Then : une question à chaque étape, la séance reste ouverte, le dialogue avance d'un état à l'autre
        $this->assertSame(['Dans quel rayon ?', DialogueStep::DEPARTMENT], [$department->speech, $department->nextState?->step]);
        $this->assertSame(['Quelle unité ? Par exemple pièce, kilo ou litre.', DialogueStep::UNIT], [$unit->speech, $unit->nextState?->step]);
        $this->assertSame(["Je crée ciboulette, fruit et légume, unité. C'est bon ?", DialogueStep::CONFIRM], [$confirm->speech, $confirm->nextState?->step]);
        $this->assertSame(["Je crée ciboulette, fruit et légume, unité. C'est bon ?"], [$confirm->reprompt]);
        foreach ([$department, $unit, $confirm] as $step) {
            $this->assertTrue($step->keepSessionOpen);
            $this->assertFalse($step->added);
        }

        // Then : le produit est créé avec le rayon et l'unité choisis, puis ajouté à la liste ; rien n'est retenu pour la phrase (le nom est exact)
        $this->assertSame([[self::USER, 'ciboulette', 'fruit-et-legume', 'unite']], $this->creator->created);
        $this->assertSame([[self::USER, 'ciboulette-' . self::USER]], $this->shoppingList->added);
        $this->assertSame([], $this->resolver->remembered);
        $this->assertEquals(new AddSpokenItemResult("C'est créé et noté : ciboulette. Autre chose ?", keepSessionOpen: true, added: true), $created);
    }

    public function testWithNoProposalYesToCreateStartsTheQuestions(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::yes(), self::choose([]));

        // Then
        $this->assertSame('Dans quel rayon ?', $result->speech);
        $this->assertSame(DialogueStep::DEPARTMENT, $result->nextState?->step);
        $this->assertSame([], $this->creator->created);
    }

    public function testTheDepartmentCanBeSaidInOtherWordsWhenAlexaDoesNotMatchIt(): void
    {
        // Given : pas de résolution d'entité, le texte entendu est rapproché des rayons du catalogue
        $state = self::choose([])->askingDepartment();

        // When
        $result = $this->answer(AlexaTestRequest::answerDepartment('produits ménagers', null), $state);

        // Then
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre.', $result->speech);
        $this->assertSame('produit-menager', $result->nextState?->department?->slug);
    }

    public function testRefusingAtTheConfirmationCreatesNothingAndAddsNothing(): void
    {
        // Given
        $confirm = self::choose([])->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))->withUnit(new DialogueOption('kilo', 'kilo'));

        // When
        $result = $this->answer(AlexaTestRequest::no(), $confirm);

        // Then : rien n'est écrit, on invite à continuer, plus d'état
        $this->assertSame([], $this->creator->created);
        $this->assertSame([], $this->shoppingList->added);
        $this->assertEquals(new AddSpokenItemResult("D'accord, je ne crée rien. Autre chose ?", keepSessionOpen: true, added: false), $result);
    }

    public function testRefusingAtAnyQuestionAbandonsPolitely(): void
    {
        foreach ([self::choose([]), self::choose([])->askingDepartment(), self::choose([])->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))] as $state) {
            // When
            $result = $this->answer(AlexaTestRequest::no(), $state);

            // Then
            $this->assertSame("D'accord, je ne crée rien. Autre chose ?", $result->speech, $state->step->value);
            $this->assertNull($result->nextState, $state->step->value);
        }
        $this->assertSame([], $this->creator->created);
        $this->assertSame([], $this->shoppingList->added);
    }

    public function testAFailureOfTheCreationIsNotCaughtSoTheTransactionIsCancelled(): void
    {
        // Given : le produit existe déjà, ou le rayon a disparu
        $confirm = self::choose([])->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))->withUnit(new DialogueOption('kilo', 'kilo'));
        $this->creator->willFail(new class('INGREDIENT_ALREADY_EXISTS', 409) extends DomainException {
        });

        // Then : rien n'est ajouté à la liste
        try {
            $this->answer(AlexaTestRequest::yes(), $confirm);
            $this->fail('DomainException attendue.');
        } catch (DomainException) {
        }
        $this->assertSame([], $this->shoppingList->added);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Incompréhension, aide, état forgé
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testTheFirstMisunderstandingAsksAgainAndTheSecondOneAbandons(): void
    {
        // Given
        $state = self::choose([])->askingDepartment();

        // When
        $first = $this->answer(AlexaTestRequest::answerDepartment('zébulon', null), $state);
        $second = $this->answer(AlexaTestRequest::answerDepartment('zébulon', null), $first->nextState);

        // Then
        $this->assertSame(["Je n'ai pas compris. Dans quel rayon ?", 1, 'Dans quel rayon ?'], [$first->speech, $first->nextState?->misses, $first->reprompt]);
        $this->assertTrue($first->keepSessionOpen);
        $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. On laisse tomber. Autre chose ?", keepSessionOpen: true, added: false), $second);
        $this->assertSame([], $this->creator->created);
    }

    public function testTheMissesStartOverWhenTheStepChanges(): void
    {
        // Given : une réponse incomprise à la question du rayon, puis un rayon compris, puis une unité incomprise
        $department = $this->answer(AlexaTestRequest::answerDepartment('zébulon', null), self::choose([])->askingDepartment());
        $unit = $this->answer(AlexaTestRequest::answerDepartment('boucherie', 'boucherie'), $department->nextState);

        // When
        $missedUnit = $this->answer(AlexaTestRequest::answerUnit('zébulon', null), $unit->nextState);

        // Then : la première incompréhension de l'étape suivante ne met pas fin au dialogue
        $this->assertSame(0, $unit->nextState?->misses);
        $this->assertSame(["Je n'ai pas compris. Quelle unité ? Par exemple pièce, kilo ou litre.", 1], [$missedUnit->speech, $missedUnit->nextState?->misses]);
    }

    public function testYesWithSeveralProposalsAsksWhichOneAndCountsAsAMiss(): void
    {
        // Given
        $state = self::choose(['persil', 'basilic']);

        // When
        $first = $this->answer(AlexaTestRequest::yes(), $state);
        $second = $this->answer(AlexaTestRequest::yes(), $first->nextState);

        // Then
        $this->assertSame(['Lequel ? persil ou basilic. Ou un nouveau produit ?', 1], [$first->speech, $first->nextState?->misses]);
        $this->assertSame("Je n'ai pas compris. On laisse tomber. Autre chose ?", $second->speech);
        $this->assertNull($second->nextState);
        $this->assertSame([], $this->shoppingList->added);
    }

    public function testHelpRepeatsTheQuestionWithoutCountingAMiss(): void
    {
        // Given
        $state = self::choose(['persil', 'basilic']);

        // When
        $result = $this->answer(AlexaTestRequest::intent('AMAZON.HelpIntent'), $state);

        // Then
        $this->assertSame('Tu veux dire persil ou basilic ? Ou un nouveau produit ?', $result->speech);
        $this->assertEquals($state, $result->nextState);
        $this->assertTrue($result->keepSessionOpen);
    }

    public function testTheFallbackIsAMiss(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::intent('AMAZON.FallbackIntent'), self::choose([])->askingDepartment());

        // Then
        $this->assertSame(1, $result->nextState?->misses);
    }

    public function testAForgedStateWithTheIngredientOfAnotherUserAddsNothingAndRemembersNothing(): void
    {
        // Given : l'état porte le slug d'un ingrédient qui n'est pas à l'utilisateur ; le port de la liste le refuse
        $this->shoppingList->willThrow(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then : l'erreur n'est pas attrapée (la transaction annule tout) et la mémoire n'est pas touchée
        try {
            $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));
            $this->fail('DomainException attendue.');
        } catch (DomainException) {
        }
        $this->assertSame([], $this->shoppingList->added);
        $this->assertSame([], $this->resolver->remembered);
    }

    public function testAFailureOfTheMemoryIsNotCaughtEither(): void
    {
        // Given
        $this->resolver->willFailToRemember(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then
        $this->expectException(DomainException::class);
        $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));
    }
}
