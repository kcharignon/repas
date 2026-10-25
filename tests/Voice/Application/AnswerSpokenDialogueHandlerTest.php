<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\Attributes\DataProvider;
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
            new SpokenIngredientNamer(),
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
        $this->assertEquals(new AddSpokenItemResult("C'est noté : basilic. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
    }

    public function testSayingTheNameOfAProposalWorksToo(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::addItem('du persil'), self::choose(['persil', 'basilic']));

        // Then
        $this->assertSame([[self::USER, 'persil-u']], $this->shoppingList->added);
        $this->assertSame("C'est noté : persil. Je m'en souviendrai.", $result->speech);
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
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Je m'en souviendrai.", $started->speech);
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Ta liste précédente est partie au drive.", $sent->speech);
        $this->assertCount(2, $this->resolver->remembered);
    }

    public function testAnotherArticleAbandonsTheDialogueAndIsHandledAsANewRequest(): void
    {
        // Given : du pain existe
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose(['persil', 'basilic']));

        // Then : ajouté, et la phrase d'origine est retenue pour cet article (il corrige la proposition)
        $this->assertSame([[self::USER, 'pain-u']], $this->shoppingList->added);
        $this->assertSame([[self::USER, 'de la ciboulette', 'pain-u']], $this->resolver->remembered);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pain. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
        $this->assertSame([[self::USER, 'du pain']], $this->resolver->asked);
    }

    #[DataProvider('rememberedConfidences')]
    public function testAnotherArticleCorrectsTheProposalWhenTheChoiceIsSureOrProbable(string $confidence): void
    {
        // Given : « patates » -> pâte, pâte brisée, pâte feuilletée ; « non, pomme de terre »
        $this->resolver->willResolve('de la pomme de terre', new ResolvedIngredient('pomme-de-terre-u', 'pomme de terre', 'fruits et légumes', $confidence));
        $state = SpokenDialogueState::choose('des patates', [new DialogueOption('pate-u', 'pâte'), new DialogueOption('pate-brisee-u', 'pâte brisée'), new DialogueOption('pate-feuilletee-u', 'pâte feuilletée')], 'patates');

        // When
        $result = $this->answer(AlexaTestRequest::addItem('de la pomme de terre'), $state);

        // Then : ajouté, "patates" retenu pour pomme de terre (la phrase originale, pas la nouvelle), une seule lecture
        $this->assertSame([[self::USER, 'pomme-de-terre-u']], $this->shoppingList->added);
        $this->assertSame([[self::USER, 'des patates', 'pomme-de-terre-u']], $this->resolver->remembered);
        $this->assertSame([[self::USER, 'de la pomme de terre']], $this->resolver->asked);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pomme de terre. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rememberedConfidences(): iterable
    {
        yield 'sûr' => [ResolvedIngredient::SURE];
        yield 'probable' => [ResolvedIngredient::PROBABLE];
    }

    public function testAnotherArticleWhoseChoiceIsUnsureIsAddedButNothingIsRememberedNorPromised(): void
    {
        // Given : « patates » -> propositions ; « pomme de terre » touche deux ingrédients (incertain)
        $this->resolver->willResolve('de la pomme de terre', new ResolvedIngredient('pomme-de-terre-u', 'pomme de terre', 'fruits et légumes', ResolvedIngredient::UNSURE));
        $state = SpokenDialogueState::choose('des patates', [new DialogueOption('pate-u', 'pâte'), new DialogueOption('pate-brisee-u', 'pâte brisée')], 'patates');

        // When
        $result = $this->answer(AlexaTestRequest::addItem('de la pomme de terre'), $state);

        // Then : l'article est ajouté et dit avec son rayon (le choix est incertain), aucune mémoire, aucune promesse
        $this->assertSame([[self::USER, 'pomme-de-terre-u']], $this->shoppingList->added);
        $this->assertSame([], $this->resolver->remembered);
        $this->assertEquals(new AddSpokenItemResult("J'ai mis pomme de terre, fruits et légumes.", keepSessionOpen: false, added: true), $result);
    }

    #[DataProvider('allConfidences')]
    public function testAnotherArticleWhenNothingWasProposedIsAnOrdinaryAddAndNothingIsRemembered(string $confidence): void
    {
        // Given : « de la ciboulette » -> « Je le crée ? » (aucune proposition) ; « non, du pain » : il change d'avis, il ne corrige rien
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain-u', 'pain', 'boulangerie', $confidence));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose([]));

        // Then : ajouté comme un ajout ordinaire, "ciboulette" n'est JAMAIS retenue pour le pain, aucune promesse
        $this->assertSame([[self::USER, 'pain-u']], $this->shoppingList->added);
        $this->assertSame([], $this->resolver->remembered);
        $this->assertStringNotContainsString('souviendrai', $result->speech);
        $this->assertSame($confidence === ResolvedIngredient::SURE ? "C'est noté : pain." : "J'ai mis pain, boulangerie.", $result->speech);
        $this->assertNull($result->nextState);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allConfidences(): iterable
    {
        yield 'sûr' => [ResolvedIngredient::SURE];
        yield 'probable' => [ResolvedIngredient::PROBABLE];
        yield 'incertain' => [ResolvedIngredient::UNSURE];
    }

    public function testAnOrdinaryAddAfterNothingWasProposedSaysSoOnAFreshListToo(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: false));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose([]));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", $result->speech);
        $this->assertSame([], $this->resolver->remembered);
    }

    public function testCorrectingTheProposalOnAFreshListSaysSoAndStillRemembers(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose(['persil']));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain. Ta liste précédente est partie au drive.", $result->speech);
        $this->assertSame([[self::USER, 'de la ciboulette', 'pain-u']], $this->resolver->remembered);
    }

    public function testAFailureOfTheMemoryWhenCorrectingIsNotCaught(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));
        $this->resolver->willFailToRemember(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then
        $this->expectException(DomainException::class);
        $this->answer(AlexaTestRequest::addItem('du pain'), self::choose(['persil']));
    }

    public function testNamingOneOfTheProposalsIsStillAPickNotACorrection(): void
    {
        // Given : "du persil" existe aussi comme ingrédient sûr, mais la proposition passe avant : aucune lecture de plus
        $this->resolver->willResolve('du persil', new ResolvedIngredient('autre-u', 'autre', 'x', ResolvedIngredient::SURE));

        // When
        $this->answer(AlexaTestRequest::addItem('du persil'), self::choose(['persil', 'basilic']));

        // Then
        $this->assertSame([[self::USER, 'persil-u']], $this->shoppingList->added);
        $this->assertSame([], $this->resolver->asked);
    }

    public function testAnotherUnknownArticleStartsANewDialogue(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::addItem('de la menthe'), self::choose(['persil', 'basilic']));

        // Then : rien n'est retenu, le dialogue repart pour la NOUVELLE phrase
        $this->assertSame([], $this->resolver->remembered);
        $this->assertSame([], $this->shoppingList->added);
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
        $this->assertSame(['Dans quel rayon ? Dis lesquels pour les entendre.', DialogueStep::DEPARTMENT], [$department->speech, $department->nextState?->step]);
        $this->assertSame(['Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.', DialogueStep::UNIT], [$unit->speech, $unit->nextState?->step]);
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
        $this->assertEquals(new AddSpokenItemResult("C'est créé et noté : ciboulette, rayon fruit et légume.", keepSessionOpen: false, added: true), $created);
    }

    #[DataProvider('spokenPhrases')]
    public function testTheNameOfAnHonestStateIsCreatedUnchanged(string $phrase, string $expectedName): void
    {
        // Given : un état comme le dialogue le fabrique (le nom vient de `SpokenIngredientNamer`)
        $name = new SpokenIngredientNamer()->nameFrom($phrase);
        $confirm = SpokenDialogueState::choose($phrase, [], $name ?? '')->askingDepartment()
            ->withDepartment(new DialogueOption('boucherie', 'boucherie'))
            ->withUnit(new DialogueOption('kilo', 'kilo'));

        // When
        $this->answer(AlexaTestRequest::yes(), $confirm);

        // Then : exactement ce nom, ni retouché ni abrégé
        $this->assertSame($expectedName, $name);
        $this->assertSame([[self::USER, $expectedName, 'boucherie', 'kilo']], $this->creator->created);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spokenPhrases(): iterable
    {
        yield 'article du' => ['du boudin noir', 'boudin noir'];
        yield 'article de la' => ['de la ciboulette', 'ciboulette'];
        yield 'article de l\'' => ['de l\'huile d\'olive', 'huile d\'olive'];
        yield 'des et accents' => ['des Tomates Cerises', 'tomates cerises'];
        yield 'tiret' => ['du porc-épic', 'porc-épic'];
        yield 'apostrophe typographique' => ['de l’ail', 'ail'];
        yield 'article seul' => ['la', 'la'];
        yield 'nom de soixante caractères' => [str_repeat('a', 60), str_repeat('a', 60)];
    }

    public function testAForgedNameIsNormalisedBeforeTheProductIsCreated(): void
    {
        // Given : à la confirmation, un état forgé porte un nom qui n'est jamais passé par le normalisateur
        $confirm = SpokenDialogueState::choose('de la ciboulette', [], '<b>ÉVIL</b> "Ingrédient" & ' . str_repeat('x', 20))->askingDepartment()
            ->withDepartment(new DialogueOption('boucherie', 'boucherie'))
            ->withUnit(new DialogueOption('kilo', 'kilo'));

        // When
        $result = $this->answer(AlexaTestRequest::yes(), $confirm);

        // Then : le produit est créé sous le nom normalisé (minuscules, plus aucun signe), jamais sous le nom reçu
        $this->assertSame([[self::USER, 'b évil b ingrédient xxxxxxxxxxxxxxxxxxxx', 'boucherie', 'kilo']], $this->creator->created);
        $this->assertSame([[self::USER, 'b évil b ingrédient xxxxxxxxxxxxxxxxxxxx-' . self::USER]], $this->shoppingList->added);
        $this->assertTrue($result->added);
    }

    #[DataProvider('refusedNames')]
    public function testAForgedNameTheNormaliserRefusesCreatesNothingAndAsksAgain(string $forgedName): void
    {
        // Given
        $confirm = SpokenDialogueState::choose('de la ciboulette', [], $forgedName)->askingDepartment()
            ->withDepartment(new DialogueOption('boucherie', 'boucherie'))
            ->withUnit(new DialogueOption('kilo', 'kilo'));

        // When : « oui », deux fois
        $first = $this->answer(AlexaTestRequest::yes(), $confirm);
        $second = $this->answer(AlexaTestRequest::yes(), $first->nextState);

        // Then : rien n'est créé ni ajouté ; la première fois « Je n'ai pas compris » et la question de l'étape (comme une réponse incomprise), la
        // seconde fois on abandonne
        $this->assertSame([], $this->creator->created);
        $this->assertSame([], $this->shoppingList->added);
        $this->assertTrue($first->keepSessionOpen);
        $this->assertFalse($first->added);
        $this->assertStringStartsWith("Je n'ai pas compris. Je crée ", $first->speech);
        $this->assertStringEndsWith("C'est bon ?", $first->speech);
        $this->assertSame([DialogueStep::CONFIRM, 1], [$first->nextState?->step, $first->nextState?->misses]);
        $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. On laisse tomber.", keepSessionOpen: false, added: false), $second);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'sans aucune lettre' => ['12 34'];
        yield 'que des signes' => ['<<>> "" &&'];
        yield 'trop long' => [str_repeat('a', 61)];
        yield 'dans la limite de l\'état mais trop long pour un nom' => [str_repeat('ab ', 33)];
    }

    public function testWithNoProposalYesToCreateStartsTheQuestions(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::yes(), self::choose([]));

        // Then
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $result->speech);
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
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.', $result->speech);
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
        $this->assertEquals(new AddSpokenItemResult("D'accord, je ne crée rien.", keepSessionOpen: false, added: false), $result);
    }

    public function testRefusingAtAnyQuestionAbandonsPolitely(): void
    {
        foreach ([self::choose([]), self::choose([])->askingDepartment(), self::choose([])->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))] as $state) {
            // When
            $result = $this->answer(AlexaTestRequest::no(), $state);

            // Then
            $this->assertSame("D'accord, je ne crée rien.", $result->speech, $state->step->value);
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
        $this->assertSame(["Je n'ai pas compris. Dans quel rayon ? Dis lesquels pour les entendre.", 1, 'Dans quel rayon ? Dis lesquels pour les entendre.'], [$first->speech, $first->nextState?->misses, $first->reprompt]);
        $this->assertTrue($first->keepSessionOpen);
        $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. On laisse tomber.", keepSessionOpen: false, added: false), $second);
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
        $this->assertSame(["Je n'ai pas compris. Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.", 1], [$missedUnit->speech, $missedUnit->nextState?->misses]);
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
        $this->assertSame("Je n'ai pas compris. On laisse tomber.", $second->speech);
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

    public function testWhichOnesAtTheDepartmentReadsEveryDepartmentThenAsksAgainWithoutChangingAnything(): void
    {
        // Given : une première réponse incomprise (misses = 1), puis l'utilisateur demande la liste
        $state = $this->answer(AlexaTestRequest::answerDepartment('zébulon', null), self::choose([])->askingDepartment())->nextState;

        // When
        $result = $this->answer(AlexaTestRequest::listChoices(), $state);

        // Then : tous les rayons du catalogue, puis la question ; l'état est celui d'avant, les réponses incomprises n'ont pas bougé
        $this->assertSame('Les rayons : boucherie, fruit et légume, produit ménager. Lequel ?', $result->speech);
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $result->reprompt);
        $this->assertEquals($state, $result->nextState);
        $this->assertSame(1, $result->nextState?->misses);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
        $this->assertSame([], $this->creator->created);
        $this->assertSame([], $this->shoppingList->added);

        // When : on peut la redemander autant de fois qu'on veut, sans jamais abandonner
        $again = $this->answer(AlexaTestRequest::listChoices(), $result->nextState);
        $third = $this->answer(AlexaTestRequest::intent('AMAZON.HelpIntent'), $again->nextState);

        // Then
        $this->assertSame($result->speech, $again->speech);
        $this->assertSame($result->speech, $third->speech, "« aide » donne la même liste");
        $this->assertSame(1, $third->nextState?->misses);
    }

    public function testWhichOnesAtTheUnitReadsEveryUnitThenAsksAgainWithoutChangingAnything(): void
    {
        // Given
        $state = self::choose([])->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'));

        // When
        $result = $this->answer(AlexaTestRequest::listChoices(), $state);

        // Then
        $this->assertSame('Les unités : kilo, unité, paquet. Laquelle ?', $result->speech);
        $this->assertSame('Quelle unité ? Par exemple pièce, kilo ou litre. Dis lesquelles pour les entendre.', $result->reprompt);
        $this->assertEquals($state, $result->nextState);
        $this->assertSame(0, $result->nextState?->misses);
        $this->assertSame($result->speech, $this->answer(AlexaTestRequest::intent('AMAZON.HelpIntent'), $state)->speech);
    }

    public function testWhichOnesAtTheOtherStepsRepeatsTheQuestion(): void
    {
        // Given
        $confirm = self::choose([])->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))->withUnit(new DialogueOption('kilo', 'kilo'));
        $choose = self::choose(['persil', 'basilic']);

        // When
        $onConfirm = $this->answer(AlexaTestRequest::listChoices(), $confirm);
        $onChoose = $this->answer(AlexaTestRequest::listChoices(), $choose);

        // Then : la question de l'étape, pas de liste, l'état intact
        $this->assertSame("Je crée ciboulette, boucherie, kilo. C'est bon ?", $onConfirm->speech);
        $this->assertSame('Tu veux dire persil ou basilic ? Ou un nouveau produit ?', $onChoose->speech);
        $this->assertEquals($confirm, $onConfirm->nextState);
        $this->assertEquals($choose, $onChoose->nextState);
    }

    public function testWhichOnesWithAnEmptyCatalogueJustAsksTheQuestion(): void
    {
        // Given : un catalogue vide (ne devrait pas arriver)
        $empty = new SpokenIngredientCreatorInMemory();
        $empty->setCatalogues([], []);
        $handler = new AnswerSpokenDialogueHandler(new SpokenAnswerInterpreter(), $empty, $this->resolver, $this->shoppingList, new AddSpokenItemHandler($this->resolver, $this->shoppingList, new SpokenIngredientNamer()), new SpokenIngredientNamer());
        $state = self::choose([])->askingDepartment();

        // When
        $result = $handler(new AnswerSpokenDialogueCommand(self::USER, AlexaRequest::fromDecoded(AlexaTestRequest::listChoices()->inOpenSession()->toArray()), $state));

        // Then
        $this->assertSame('Dans quel rayon ? Dis lesquels pour les entendre.', $result->speech);
        $this->assertEquals($state, $result->nextState);
    }

    public function testACreatedProductRepeatsItsDepartmentAlsoWhenANewListIsStarted(): void
    {
        // Given
        $confirm = self::choose([])->askingDepartment()->withDepartment(new DialogueOption('fruit-et-legume', 'fruit & légume'))->withUnit(new DialogueOption('unite', 'unité'));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $result = $this->answer(AlexaTestRequest::yes(), $confirm);

        // Then
        $this->assertSame("J'ai créé ciboulette, rayon fruit et légume, et commencé une nouvelle liste. Ta liste précédente est partie au drive.", $result->speech);
    }

    private static function tie(): SpokenDialogueState
    {
        return SpokenDialogueState::choose('des tomates', [
            new DialogueOption('tomate-fl', 'Tomate', 'fruit-et-legume', 'Fruit & légume'),
            new DialogueOption('tomate-sg', 'Tomate', 'surgele', 'Surgelé'),
        ], 'tomate');
    }

    public function testTellingIngredientsApartByDepartmentAddsThemRemembersThePhraseAndRepeatsTheDepartment(): void
    {
        // When : « en surgelé »
        $result = $this->answer(AlexaTestRequest::answerDepartment('surgelé', 'surgele'), self::tie());

        // Then : l'article est ajouté, la phrase d'ORIGINE est retenue pour lui, le rayon est répété, la séance se ferme
        $this->assertSame([[self::USER, 'tomate-sg']], $this->shoppingList->added);
        $this->assertSame([[self::USER, 'des tomates', 'tomate-sg']], $this->resolver->remembered);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : tomate, surgelé. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
    }

    public function testTheListStartedAndTheSentListAreSaidInsteadOfThePromiseWhenTellingApart(): void
    {
        // Given
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $result = $this->answer(AlexaTestRequest::chooseOption('le premier', '1'), self::tie());

        // Then : la phrase est retenue quand même
        $this->assertSame("J'ai commencé une nouvelle liste avec tomate, fruit et légume. Ta liste précédente est partie au drive.", $result->speech);
        $this->assertSame([[self::USER, 'des tomates', 'tomate-fl']], $this->resolver->remembered);
    }

    public function testTwoSameDepartmentAnswersAskWhichOneAndTwoMissesGiveUp(): void
    {
        // Given : un rayon que les deux propositions partagent
        $sameDepartment = SpokenDialogueState::choose('des tomates', [
            new DialogueOption('a', 'Tomate', 'conserve', 'Conserve'),
            new DialogueOption('b', 'Tomate bio', 'conserve', 'Conserve'),
        ], 'tomate');

        // When
        $first = $this->answer(AlexaTestRequest::answerDepartment('conserve', 'conserve'), $sameDepartment);
        $second = $this->answer(AlexaTestRequest::answerDepartment('conserve', 'conserve'), $first->nextState);

        // Then : on redemande par le nom et le rayon, puis on abandonne ; rien n'est écrit
        $this->assertSame(['Tu veux dire tomate en conserve ou tomate bio en conserve ?', 1], [$first->speech, $first->nextState?->misses]);
        $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. On laisse tomber.", keepSessionOpen: false, added: false), $second);
        $this->assertSame([], $this->shoppingList->added);
        $this->assertSame([], $this->resolver->remembered);
    }

    public function testRefusingTheDepartmentQuestionAddsNothingAndHasNoNewProductIssue(): void
    {
        $refused = $this->answer(AlexaTestRequest::no(), self::tie());
        $newProduct = $this->answer(AlexaTestRequest::createItem(), self::tie());

        $this->assertEquals(new AddSpokenItemResult("D'accord, je n'ajoute rien.", keepSessionOpen: false, added: false), $refused);
        $this->assertSame(['Je n\'ai pas compris. Tomate : en fruit et légume ou en surgelé ?', DialogueStep::CHOOSE], [$newProduct->speech, $newProduct->nextState?->step]);
        $this->assertSame([], $this->creator->created);
        $this->assertSame([], $this->shoppingList->added);
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
