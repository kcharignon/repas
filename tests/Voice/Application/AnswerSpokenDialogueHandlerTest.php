<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Voice\Domain\Model\ResolvedIngredient;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemHandler;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\AddSpokenMeal\AddSpokenMealHandler;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueCommand;
use Repas\Voice\Application\Command\AnswerSpokenDialogue\AnswerSpokenDialogueHandler;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemHandler;
use Repas\Voice\Application\Command\RemoveSpokenMeal\RemoveSpokenMealHandler;
use Repas\Voice\Domain\Model\AddedItem;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\ChangedMealServings;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Model\ListedMeal;
use Repas\Voice\Domain\Model\PlannedSpokenMeal;
use Repas\Voice\Domain\Model\RemovedItem;
use Repas\Voice\Domain\Model\RemovedSpokenMeal;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Service\SpokenAnswerInterpreter;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Repas\Voice\Domain\Service\SpokenListAnswerer;

/**
 * Les réponses aux questions d'un produit inconnu (ADR 0029 décision 11, VOX-303) : les ingrédients et la liste passent par la vraie chaîne (adapters de
 * `Voice`, Open Host Services de `Repas`, `RepasForVoice`), les recettes et les repas passent aussi par elle (le bus de commandes est simulé) : aucune base, aucun noyau. Les flux complets : proposition choisie puis retenue, création guidée, refus, incompréhension. L'interprétation de chaque réponse est
 * prouvée par `SpokenAnswerInterpreterTest`, la création et la mémoire par les tests de `Repas`, le tout câblé par `AlexaWebhookApiFlowTest`.
 */
class AnswerSpokenDialogueHandlerTest extends TestCase
{
    private const string USER = 'user-1';

    private RepasForVoice $repas;
    private AnswerSpokenDialogueHandler $handler;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
        $this->handler = $this->handlerWith($this->repas);
    }

    private function handlerWith(RepasForVoice $repas): AnswerSpokenDialogueHandler
    {
        $namer = new SpokenIngredientNamer();
        $resolution = $repas->resolutionPort();

        return new AnswerSpokenDialogueHandler(
            new SpokenAnswerInterpreter(),
            $repas->creationPort(),
            $resolution,
            $repas->itemsPort(),
            new AddSpokenItemHandler($resolution, $repas->itemsPort(), $namer),
            $namer,
            new RemoveSpokenItemHandler($repas->lineLookupPort(), $repas->itemsPort(), $namer),
            $repas->lineLookupPort(),
            new SpokenListAnswerer($namer),
            new AddSpokenMealHandler($repas->recipeResolutionPort(), $repas->mealPlanPort(), $namer),
            new RemoveSpokenMealHandler($repas->mealLookupPort(), $repas->mealPlanPort(), $namer),
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
        $this->assertSame([[self::USER, 'basilic-u']], $this->repas->added());
        $this->assertSame([[self::USER, 'de la ciboulette', 'basilic-u']], $this->repas->remembered());
        $this->assertEquals(new AddSpokenItemResult("C'est noté : basilic. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
    }

    public function testSayingTheNameOfAProposalWorksToo(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::addItem('du persil'), self::choose(['persil', 'basilic']));

        // Then
        $this->assertSame([[self::USER, 'persil-u']], $this->repas->added());
        $this->assertSame("C'est noté : persil. Je m'en souviendrai.", $result->speech);
    }

    public function testYesWithASingleProposalChoosesIt(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::yes(), self::choose(['persil']));

        // Then
        $this->assertSame([[self::USER, 'persil-u']], $this->repas->added());
        $this->assertSame([[self::USER, 'de la ciboulette', 'persil-u']], $this->repas->remembered());
        $this->assertNull($result->nextState);
    }

    public function testANewListIsSaidAndThePromiseOfMemoryGivesWayToTheSentListWhenThereIsOne(): void
    {
        // Given
        $this->repas->willAdd(new AddedItem(listCreated: true, previousListSent: false));
        $started = $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));
        $this->repas->willAdd(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $sent = $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));

        // Then : la phrase est retenue dans les deux cas
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Je m'en souviendrai.", $started->speech);
        $this->assertSame("J'ai commencé une nouvelle liste avec persil. Ta liste précédente est partie au drive.", $sent->speech);
        $this->assertCount(2, $this->repas->remembered());
    }

    public function testAnotherArticleAbandonsTheDialogueAndIsHandledAsANewRequest(): void
    {
        // Given : du pain existe
        $this->repas->willResolve(new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose(['persil', 'basilic']));

        // Then : ajouté, et la phrase d'origine est retenue pour cet article (il corrige la proposition)
        $this->assertSame([[self::USER, 'pain-u']], $this->repas->added());
        $this->assertSame([[self::USER, 'de la ciboulette', 'pain-u']], $this->repas->remembered());
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pain. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
        $this->assertSame([[self::USER, 'du pain']], $this->repas->asked);
    }

    #[DataProvider('rememberedConfidences')]
    public function testAnotherArticleCorrectsTheProposalWhenTheChoiceIsSureOrProbable(string $confidence): void
    {
        // Given : « patates » -> pâte, pâte brisée, pâte feuilletée ; « non, pomme de terre »
        $this->repas->willResolve(new ResolvedIngredient('pomme-de-terre-u', 'pomme de terre', 'fruits et légumes', $confidence));
        $state = SpokenDialogueState::choose('des patates', [new DialogueOption('pate-u', 'pâte'), new DialogueOption('pate-brisee-u', 'pâte brisée'), new DialogueOption('pate-feuilletee-u', 'pâte feuilletée')], 'patates');

        // When
        $result = $this->answer(AlexaTestRequest::addItem('de la pomme de terre'), $state);

        // Then : ajouté, "patates" retenu pour pomme de terre (la phrase originale, pas la nouvelle), une seule lecture
        $this->assertSame([[self::USER, 'pomme-de-terre-u']], $this->repas->added());
        $this->assertSame([[self::USER, 'des patates', 'pomme-de-terre-u']], $this->repas->remembered());
        $this->assertSame([[self::USER, 'de la pomme de terre']], $this->repas->asked);
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
        $this->repas->willResolve(new ResolvedIngredient('pomme-de-terre-u', 'pomme de terre', 'fruits et légumes', ResolvedIngredient::UNSURE));
        $state = SpokenDialogueState::choose('des patates', [new DialogueOption('pate-u', 'pâte'), new DialogueOption('pate-brisee-u', 'pâte brisée')], 'patates');

        // When
        $result = $this->answer(AlexaTestRequest::addItem('de la pomme de terre'), $state);

        // Then : l'article est ajouté et dit avec son rayon (le choix est incertain), aucune mémoire, aucune promesse
        $this->assertSame([[self::USER, 'pomme-de-terre-u']], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
        $this->assertEquals(new AddSpokenItemResult("J'ai mis pomme de terre, fruits et légumes.", keepSessionOpen: false, added: true), $result);
    }

    #[DataProvider('allConfidences')]
    public function testAnotherArticleWhenNothingWasProposedIsAnOrdinaryAddAndNothingIsRemembered(string $confidence): void
    {
        // Given : « de la ciboulette » -> « Je le crée ? » (aucune proposition) ; « non, du pain » : il change d'avis, il ne corrige rien
        $this->repas->willResolve(new ResolvedIngredient('pain-u', 'pain', 'boulangerie', $confidence));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose([]));

        // Then : ajouté comme un ajout ordinaire, "ciboulette" n'est JAMAIS retenue pour le pain, aucune promesse
        $this->assertSame([[self::USER, 'pain-u']], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
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
        $this->repas->willResolve(new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));
        $this->repas->willAdd(new AddedItem(listCreated: true, previousListSent: false));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose([]));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", $result->speech);
        $this->assertSame([], $this->repas->remembered());
    }

    public function testCorrectingTheProposalOnAFreshListSaysSoAndStillRemembers(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));
        $this->repas->willAdd(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::choose(['persil']));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain. Ta liste précédente est partie au drive.", $result->speech);
        $this->assertSame([[self::USER, 'de la ciboulette', 'pain-u']], $this->repas->remembered());
    }

    public function testAFailureOfTheMemoryWhenCorrectingIsNotCaught(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));
        $this->repas->willFailToRemember(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then
        $this->expectException(DomainException::class);
        $this->answer(AlexaTestRequest::addItem('du pain'), self::choose(['persil']));
    }

    public function testNamingOneOfTheProposalsIsStillAPickNotACorrection(): void
    {
        // Given : "du persil" existe aussi comme ingrédient sûr, mais la proposition passe avant : aucune lecture de plus
        $this->repas->willResolve(new ResolvedIngredient('autre-u', 'autre', 'x', ResolvedIngredient::SURE));

        // When
        $this->answer(AlexaTestRequest::addItem('du persil'), self::choose(['persil', 'basilic']));

        // Then
        $this->assertSame([[self::USER, 'persil-u']], $this->repas->added());
        $this->assertSame([], $this->repas->asked);
    }

    public function testAnotherUnknownArticleStartsANewDialogue(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::addItem('de la menthe'), self::choose(['persil', 'basilic']));

        // Then : rien n'est retenu, le dialogue repart pour la NOUVELLE phrase
        $this->assertSame([], $this->repas->remembered());
        $this->assertSame([], $this->repas->added());
        $this->assertSame('Je ne connais pas menthe. Je le crée ? Ou c\'est une recette ?', $result->speech);
        $this->assertSame(['de la menthe', 'menthe', DialogueStep::CHOOSE], [$result->nextState?->phrase, $result->nextState?->name, $result->nextState?->step]);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Création guidée : rayon, unité, confirmation
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testTheQuantityAndUnitSaidAtTheStartApplyToTheChosenProposal(): void
    {
        // Given : « trois paquets de ciboulette » -> des propositions
        $state = SpokenDialogueState::choose('ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette', 3, 'paquet');
        $this->repas->willAdd(new AddedItem(listCreated: false, previousListSent: false, quantity: 3.0, unitSlug: 'paquet', unitName: 'paquet', addedQuantity: 3.0));

        // When : « oui »
        $result = $this->answer(AlexaTestRequest::yes(), $state);

        // Then : trois paquets de persil, dits
        $this->assertSame([[3, 'paquet']], $this->repas->addedWith());
        $this->assertSame("C'est noté : 3 paquets de persil. Je m'en souviendrai.", $result->speech);
    }

    public function testTheQuantityAndUnitSaidAtTheStartApplyToTheCreatedProduct(): void
    {
        // Given : « trois ciboulettes » -> « un nouveau produit » -> ... -> « oui »
        $state = SpokenDialogueState::choose('ciboulette', [], 'ciboulette', 3);
        $this->repas->willAdd(new AddedItem(listCreated: false, previousListSent: false, quantity: 3.0, addedQuantity: 3.0));
        $department = $this->answer(AlexaTestRequest::yes(), $state);
        $unit = $this->answer(AlexaTestRequest::answerDepartment('fruits et légumes', 'fruit-et-legume'), $department->nextState);
        $confirm = $this->answer(AlexaTestRequest::answerUnit('pièces', 'unite'), $unit->nextState);

        // When
        $created = $this->answer(AlexaTestRequest::yes(), $confirm->nextState);

        // Then : la quantité a traversé toutes les étapes
        $this->assertSame([[3, null]], $this->repas->addedWith());
        $this->assertSame("C'est créé et noté : 3 ciboulettes, rayon fruit et légume.", $created->speech);
    }

    public function testAnotherArticleSaidWithItsOwnQuantityAndUnitCarriesThemToTheNewRequest(): void
    {
        // Given : des propositions faites pour « trois croquettes », puis « deux kilos de tomates »
        $this->repas->willResolve(new ResolvedIngredient('tomate', 'Tomate', 'Fruits et légumes', ResolvedIngredient::SURE));
        $state = SpokenDialogueState::choose('croquettes', [new DialogueOption('pate-u', 'pâte')], 'croquettes', 3);

        // When
        $this->answer(AlexaTestRequest::addQuantified('tomates', 2, 'kilos', 'kilo'), $state);

        // Then : la quantité et l'unité du nouvel article, pas celles de l'ancien
        $this->assertSame([[2, 'kilo']], $this->repas->addedWith());
    }

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
        $this->assertSame([[self::USER, 'ciboulette', 'fruit-et-legume', 'unite']], $this->repas->created());
        $this->assertSame([[self::USER, 'ciboulette-' . self::USER]], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
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
        $this->assertSame([[self::USER, $expectedName, 'boucherie', 'kilo']], $this->repas->created());
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
        $this->assertSame([[self::USER, 'b évil b ingrédient xxxxxxxxxxxxxxxxxxxx', 'boucherie', 'kilo']], $this->repas->created());
        $this->assertSame([[self::USER, 'b évil b ingrédient xxxxxxxxxxxxxxxxxxxx-' . self::USER]], $this->repas->added());
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
        $this->assertSame([], $this->repas->created());
        $this->assertSame([], $this->repas->added());
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
        $this->assertSame([], $this->repas->created());
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
        $this->assertSame([], $this->repas->created());
        $this->assertSame([], $this->repas->added());
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
        $this->assertSame([], $this->repas->created());
        $this->assertSame([], $this->repas->added());
    }

    public function testAFailureOfTheCreationIsNotCaughtSoTheTransactionIsCancelled(): void
    {
        // Given : le produit existe déjà, ou le rayon a disparu
        $confirm = self::choose([])->askingDepartment()->withDepartment(new DialogueOption('boucherie', 'boucherie'))->withUnit(new DialogueOption('kilo', 'kilo'));
        $this->repas->willFailToCreate(new class('INGREDIENT_ALREADY_EXISTS', 409) extends DomainException {
        });

        // Then : rien n'est ajouté à la liste
        try {
            $this->answer(AlexaTestRequest::yes(), $confirm);
            $this->fail('DomainException attendue.');
        } catch (DomainException) {
        }
        $this->assertSame([], $this->repas->added());
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
        $this->assertSame([], $this->repas->created());
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
        $this->assertSame(['Lequel ? persil ou basilic. Ou un nouveau produit, ou une recette ?', 1], [$first->speech, $first->nextState?->misses]);
        $this->assertSame("Je n'ai pas compris. On laisse tomber.", $second->speech);
        $this->assertNull($second->nextState);
        $this->assertSame([], $this->repas->added());
    }

    public function testHelpRepeatsTheQuestionWithoutCountingAMiss(): void
    {
        // Given
        $state = self::choose(['persil', 'basilic']);

        // When
        $result = $this->answer(AlexaTestRequest::intent('AMAZON.HelpIntent'), $state);

        // Then
        $this->assertSame('Tu veux dire persil ou basilic ? Ou un nouveau produit, ou une recette ?', $result->speech);
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
        $this->assertSame([], $this->repas->created());
        $this->assertSame([], $this->repas->added());

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
        $this->assertSame('Tu veux dire persil ou basilic ? Ou un nouveau produit, ou une recette ?', $onChoose->speech);
        $this->assertEquals($confirm, $onConfirm->nextState);
        $this->assertEquals($choose, $onChoose->nextState);
    }

    public function testWhichOnesWithAnEmptyCatalogueJustAsksTheQuestion(): void
    {
        // Given : un catalogue vide (ne devrait pas arriver)
        $empty = new RepasForVoice();
        $empty->setCatalogues([], []);
        $handler = $this->handlerWith($empty);
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
        $this->repas->willAdd(new AddedItem(listCreated: true, previousListSent: true));

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
        $this->assertSame([[self::USER, 'tomate-sg']], $this->repas->added());
        $this->assertSame([[self::USER, 'des tomates', 'tomate-sg']], $this->repas->remembered());
        $this->assertEquals(new AddSpokenItemResult("C'est noté : tomate, surgelé. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
    }

    public function testTheListStartedAndTheSentListAreSaidInsteadOfThePromiseWhenTellingApart(): void
    {
        // Given
        $this->repas->willAdd(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $result = $this->answer(AlexaTestRequest::chooseOption('le premier', '1'), self::tie());

        // Then : la phrase est retenue quand même
        $this->assertSame("J'ai commencé une nouvelle liste avec tomate, fruit et légume. Ta liste précédente est partie au drive.", $result->speech);
        $this->assertSame([[self::USER, 'des tomates', 'tomate-fl']], $this->repas->remembered());
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
        $this->assertSame([], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
    }

    public function testRefusingTheDepartmentQuestionAddsNothingAndHasNoNewProductIssue(): void
    {
        $refused = $this->answer(AlexaTestRequest::no(), self::tie());
        $newProduct = $this->answer(AlexaTestRequest::createItem(), self::tie());

        $this->assertEquals(new AddSpokenItemResult("D'accord, je n'ajoute rien.", keepSessionOpen: false, added: false), $refused);
        $this->assertSame(['Je n\'ai pas compris. Tomate : en fruit et légume ou en surgelé ?', DialogueStep::CHOOSE], [$newProduct->speech, $newProduct->nextState?->step]);
        $this->assertSame([], $this->repas->created());
        $this->assertSame([], $this->repas->added());
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Départager deux lignes de la liste à RETIRER (ADR 0029 décision 12) : la ligne choisie perd la quantité dite, rien n'est ajouté ni retenu
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    private static function removalTie(int $quantity = 1, ?string $unitSlug = null): SpokenDialogueState
    {
        return SpokenDialogueState::chooseToRemove('des tomates', [
            new DialogueOption('tomate-fl', 'Tomate', 'fruit-et-legume', 'Fruit & légume'),
            new DialogueOption('tomate-sg', 'Tomate', 'surgele', 'Surgelé'),
        ], 'tomate', $quantity, $unitSlug);
    }

    public function testTellingLinesApartByDepartmentRemovesFromTheChosenLineAndRemembersNothing(): void
    {
        // Given : il reste 2 tomates surgelées après le retrait
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 2.0, removed: 1.0));

        // When : « en surgelé »
        $result = $this->answer(AlexaTestRequest::answerDepartment('surgelé', 'surgele'), self::removalTie());

        // Then : la ligne surgelée perd un, rien n'est ajouté, aucune phrase n'est retenue, la séance se ferme
        $this->assertSame([[self::USER, 'tomate-sg']], $this->repas->removed());
        $this->assertSame([[1, null]], $this->repas->removedWith());
        $this->assertSame([], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
        $this->assertEquals(new AddSpokenItemResult("C'est retiré, il t'en reste 2 dans ta liste.", keepSessionOpen: false, added: false), $result);
    }

    public function testTheSaidQuantityAndUnitOfTheRemovalTravelWithTheState(): void
    {
        // Given
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 0.0, removed: 2.0, unitSlug: 'kilo', unitName: 'kilo'));

        // When : « le premier », pour un retrait de deux kilos
        $this->answer(AlexaTestRequest::chooseOption('le premier', '1'), self::removalTie(2, 'kilo'));

        // Then
        $this->assertSame([[self::USER, 'tomate-fl']], $this->repas->removed());
        $this->assertSame([[2, 'kilo']], $this->repas->removedWith());
    }

    public function testTheWholeLineIsSaidGoneWhenTheChosenLineIsUsedUp(): void
    {
        // Given
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 0.0, removed: 1.0));

        // When
        $result = $this->answer(AlexaTestRequest::answerDepartment('fruits et légumes', 'fruit-et-legume'), self::removalTie());

        // Then
        $this->assertSame("C'est retiré : tomate. Il n'y en a plus dans ta liste.", $result->speech);
    }

    public function testAChosenLineThatIsNoLongerInTheListIsSaidAbsent(): void
    {
        // Given : l'état est une entrée non fiable : la ligne a disparu, ou le slug est celui d'un autre
        $this->repas->willRemove(new RemovedItem(found: false));

        // When
        $result = $this->answer(AlexaTestRequest::chooseOption('le deuxième', '2'), self::removalTie());

        // Then
        $this->assertSame("Il n'y a pas de tomate dans ta liste.", $result->speech);
        $this->assertSame([], $this->repas->added());
    }

    public function testRefusingToTellTheLinesApartRemovesNothing(): void
    {
        // When
        $refused = $this->answer(AlexaTestRequest::no(), self::removalTie());

        // Then : « je ne retire rien », pas « je n'ajoute rien »
        $this->assertEquals(new AddSpokenItemResult("D'accord, je ne retire rien.", keepSessionOpen: false, added: false), $refused);
        $this->assertSame([], $this->repas->removed());
        $this->assertSame([], $this->repas->added());
    }

    public function testTwoUnclearAnswersGiveUpWithoutRemovingAnything(): void
    {
        // When
        $first = $this->answer(AlexaTestRequest::intent('AMAZON.FallbackIntent'), self::removalTie());
        $second = $this->answer(AlexaTestRequest::intent('AMAZON.FallbackIntent'), $first->nextState);

        // Then : l'état garde « retirer » entre les deux demandes
        $this->assertTrue($first->nextState?->isRemoval());
        $this->assertSame('Je n\'ai pas compris. Tomate : en fruit et légume ou en surgelé ?', $first->speech);
        $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. On laisse tomber.", keepSessionOpen: false, added: false), $second);
        $this->assertSame([], $this->repas->removed());
    }

    public function testSayingAnotherItemToAddInsteadIsAnOrdinaryAddNeverARemoval(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When : « ajoute du pain » au milieu d'un départage de retrait
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::removalTie());

        // Then : un ajout ordinaire, rien n'est retiré ni retenu
        $this->assertSame([[self::USER, 'pain']], $this->repas->added());
        $this->assertSame([], $this->repas->removed());
        $this->assertSame([], $this->repas->remembered());
        $this->assertSame("C'est noté : pain.", $result->speech);
    }

    public function testTheDepartmentQuestionIsAskedAgainWithTheSameWordsForARemoval(): void
    {
        // When : « oui » ne dit pas laquelle
        $result = $this->answer(AlexaTestRequest::yes(), self::removalTie());

        // Then
        $this->assertSame('Tomate : en fruit et légume ou en surgelé ?', $result->speech);
        $this->assertTrue($result->nextState?->isRemoval());
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // « Enlève … » et « est-ce qu'il y a … ? » dits au milieu d'une question : de nouvelles demandes (revue du 2026-10-25, ADR 0029 décision 12)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    public function testARemovalSaidInTheMiddleOfAQuestionIsDoneAsIfItWasSaidAlone(): void
    {
        // Given : des propositions sont faites pour « de la ciboulette » ; la liste porte trois croquettes
        $this->repas->listHas(new ListedLine('croquette', 'croquette', 'animal', 'animal', 3.0, 'unite', 'unité'));
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 2.0, removed: 1.0));

        // When : « enlève des croquettes » à « Tu veux dire persil ou basilic ? »
        $result = $this->answer(AlexaTestRequest::removeItem('croquettes'), self::choose(['persil', 'basilic']));

        // Then : la demande est faite (retirer un), le dialogue est abandonné, la séance se ferme, rien n'est ajouté ni retenu
        $this->assertSame([[self::USER, 'croquette']], $this->repas->removed());
        $this->assertSame([[1, null]], $this->repas->removedWith());
        $this->assertSame([], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
        $this->assertEquals(new AddSpokenItemResult("C'est retiré, il t'en reste 2 dans ta liste.", keepSessionOpen: false, added: false), $result);
    }

    public function testASaidQuantityAndUnitFollowTheRemovalSaidInTheMiddleOfAQuestion(): void
    {
        // Given
        $this->repas->listHas(new ListedLine('tomate', 'tomate', 'fruit-et-legume', 'fruit & légume', 5.0, 'kilo', 'kilo'));
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 3.0, removed: 2.0, unitSlug: 'kilo', unitName: 'kilo'));

        // When : « enlève deux kilos de tomates » pendant un départage de retrait
        $this->answer(AlexaTestRequest::removeItem('tomates')->withQuantity(2)->withSlot('unit', 'kilos', 'kilo'), self::removalTie());

        // Then
        $this->assertSame([[2, 'kilo']], $this->repas->removedWith());
    }

    public function testANewRemovalThatTiesAgainStartsANewRemovalDialogue(): void
    {
        // Given : deux lignes qui se valent pour la nouvelle demande
        $this->repas
            ->listHas(new ListedLine('tomate-fl', 'tomate', 'fruit-et-legume', 'fruit & légume', 2.0, 'unite', 'unité'))
            ->listHas(new ListedLine('tomate-sg', 'tomate', 'surgele', 'surgelé', 1.0, 'unite', 'unité'));

        // When : « enlève des tomates » au milieu d'une question sur la ciboulette
        $result = $this->answer(AlexaTestRequest::removeItem('tomates'), self::choose(['persil']));

        // Then : la question du rayon, un état « retirer », rien d'écrit
        $this->assertSame('Tomate : en fruit et légume ou en surgelé ?', $result->speech);
        $this->assertTrue($result->nextState?->isRemoval());
        $this->assertSame([], $this->repas->removed());
    }

    public function testACheckSaidInTheMiddleOfAQuestionIsAnsweredAndTheDialogueIsDropped(): void
    {
        // Given : deux paquets de croquettes dans la liste
        $this->repas->listHas(new ListedLine('croquette', 'croquette', 'animal', 'animal', 2.0, 'paquet', 'paquet'));

        // When : « est-ce qu'il y a des croquettes dans la liste ? » à « Je le crée ? »
        $result = $this->answer(AlexaTestRequest::checkItem('des croquettes'), self::choose([]));

        // Then : la réponse, la séance se ferme, plus d'état ; une lecture : rien n'est écrit
        $this->assertEquals(new AddSpokenItemResult('Oui, tu en as 2 paquets.', keepSessionOpen: false, added: false), $result);
        $this->assertSame([[self::USER, 'des croquettes']], $this->repas->lookedUp);
        $this->assertSame([], $this->repas->added());
        $this->assertSame([], $this->repas->removed());
        $this->assertSame([], $this->repas->remembered());
    }

    public function testACheckSaidInTheMiddleOfAQuestionWithoutAnyListSaysSo(): void
    {
        // When : le double répond « pas de liste » par défaut
        $result = $this->answer(AlexaTestRequest::checkItem('du pain'), self::removalTie());

        // Then
        $this->assertSame("Tu n'as pas de liste en cours.", $result->speech);
        $this->assertFalse($result->keepSessionOpen);
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
        $this->repas->willFailToWriteTheList(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then : l'erreur n'est pas attrapée (la transaction annule tout) et la mémoire n'est pas touchée
        try {
            $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));
            $this->fail('DomainException attendue.');
        } catch (DomainException) {
        }
        $this->assertSame([[self::USER, 'persil-u']], $this->repas->added(), 'la tentative est refusée par Repas (la transaction n\'écrit rien)');
        $this->assertSame([], $this->repas->remembered());
    }

    public function testAFailureOfTheMemoryIsNotCaughtEither(): void
    {
        // Given
        $this->repas->willFailToRemember(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then
        $this->expectException(DomainException::class);
        $this->answer(AlexaTestRequest::chooseOption('un', '1'), self::choose(['persil']));
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Un repas (ADR 0029 décision 13, VOX-305) : choisir une recette, aiguiller entre produit et recette, le nombre de personnes
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    /**
     * @param list<string> $names
     */
    private static function chooseRecipe(array $names, bool $otherSideTried = false, ?int $servings = null): SpokenDialogueState
    {
        return SpokenDialogueState::chooseRecipe(
            'gratin',
            array_map(static fn(string $name): DialogueOption => new DialogueOption($name . '-id', $name), $names),
            'gratin',
            $servings,
            $otherSideTried,
        );
    }

    private function plannedMeal(string $name, int $servings, bool $already = false, bool $listCreated = false): PlannedSpokenMeal
    {
        return new PlannedSpokenMeal($name, $servings, $already, $listCreated, false);
    }

    public function testChoosingAProposedRecipePlansTheMealAndRemembersThePhraseButTouchesNoProduct(): void
    {
        // Given
        $this->repas->willPlanMeal($this->plannedMeal('gratin de pâtes', 4));

        // When : « le deuxième »
        $result = $this->answer(AlexaTestRequest::chooseOption('le deuxième', '2'), self::chooseRecipe(['gratin dauphinois', 'gratin de pâtes']));

        // Then : le repas est planifié (nombre du compte), la phrase d'origine retenue POUR LES RECETTES ; les produits ne bougent pas
        $this->assertSame([[self::USER, 'gratin de pâtes-id', null]], $this->repas->mealsPlanned());
        $this->assertSame([[self::USER, 'gratin', 'gratin de pâtes-id']], $this->repas->rememberedRecipes());
        $this->assertSame([], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
        $this->assertEquals(new AddSpokenItemResult("C'est noté : gratin de pâtes, pour 4 personnes. Je m'en souviendrai.", keepSessionOpen: false, added: true), $result);
    }

    public function testTheServingsSaidAtTheStartAreKeptThroughTheProposals(): void
    {
        // Given
        $this->repas->willPlanMeal($this->plannedMeal('gratin dauphinois', 6));

        // When : « planifie le gratin pour six personnes » -> propositions -> « le premier »
        $this->answer(AlexaTestRequest::chooseOption('le premier', '1'), self::chooseRecipe(['gratin dauphinois', 'gratin de pâtes'], servings: 6));

        // Then
        $this->assertSame([[self::USER, 'gratin dauphinois-id', 6]], $this->repas->mealsPlanned());
    }

    public function testSayingTheNameOfAProposedRecipeWorksToo(): void
    {
        // Given
        $this->repas->willPlanMeal($this->plannedMeal('gratin de pâtes', 4));

        // When
        $this->answer(AlexaTestRequest::addMeal('gratin de pâtes'), self::chooseRecipe(['gratin dauphinois', 'gratin de pâtes']));

        // Then
        $this->assertSame([[self::USER, 'gratin de pâtes-id', null]], $this->repas->mealsPlanned());
    }

    public function testChoosingAProposedRecipeAlreadyInTheListAsksTheTotalInsteadOfPlanningTwice(): void
    {
        // Given
        $this->repas->willPlanMeal($this->plannedMeal('gratin de pâtes', 4, already: true));

        // When
        $result = $this->answer(AlexaTestRequest::chooseOption('le premier', '1'), self::chooseRecipe(['gratin de pâtes']));

        // Then : rien n'est écrit sauf la phrase retenue, une question suit
        $this->assertSame([[self::USER, 'gratin', 'gratin de pâtes-id']], $this->repas->rememberedRecipes());
        $this->assertSame(DialogueStep::SERVINGS, $result->nextState?->step);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
    }

    public function testAForgedRecipeOfAnotherUserIsRefusedAndNothingIsRemembered(): void
    {
        // Given : le port refuse la recette d'un autre
        $this->repas->willFailToWriteMeals(RecipeException::notFound('gratin dauphinois-id'));

        // Then
        $this->expectException(DomainException::class);
        try {
            $this->answer(AlexaTestRequest::chooseOption('le premier', '1'), self::chooseRecipe(['gratin dauphinois']));
        } finally {
            $this->assertSame([], $this->repas->rememberedRecipes());
        }
    }

    public function testAProductTheUserSaysInsteadOfTheProposedRecipesRemembersNothingForARecipe(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When : « non, du pain » à des recettes proposées
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), self::chooseRecipe(['gratin dauphinois', 'gratin de pâtes']));

        // Then : un ajout ordinaire, aucune phrase retenue (ni pour une recette, ni pour un produit)
        $this->assertSame([[self::USER, 'pain']], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
        $this->assertSame([], $this->repas->rememberedRecipes());
        $this->assertSame("C'est noté : pain.", $result->speech);
    }

    public function testAnotherRecipeSaidInsteadOfTheProposedOnesPlansItAndRemembersTheOriginalPhrase(): void
    {
        // Given : « gratin » -> propositions -> « les lasagnes » (autre recette de l'utilisateur)
        $this->repas->ownsRecipe('lasagnes-id', 'Lasagnes');
        $this->repas->willPlanMeal($this->plannedMeal('Lasagnes', 6));

        // When
        $result = $this->answer(AlexaTestRequest::addMeal('les lasagnes', 'six', '6'), self::chooseRecipe(['gratin dauphinois', 'gratin de pâtes']));

        // Then : planifiée pour six, et la phrase d'origine retenue pour elle (une correction)
        $this->assertSame([[self::USER, 'lasagnes-id', 6]], $this->repas->mealsPlanned());
        $this->assertSame([[self::USER, 'gratin', 'lasagnes-id']], $this->repas->rememberedRecipes());
        $this->assertSame("C'est noté : lasagnes, pour 6 personnes. Je m'en souviendrai.", $result->speech);
    }

    public function testAMealSaidToAQuestionOfProductsPlansItButRemembersNothingForTheProducts(): void
    {
        // Given
        $this->repas->ownsRecipe('lasagnes-id', 'Lasagnes');
        $this->repas->willPlanMeal($this->plannedMeal('Lasagnes', 4));

        // When : « Tu veux dire persil ou basilic ? » -> « planifie le repas lasagnes »
        $result = $this->answer(AlexaTestRequest::addMeal('lasagnes'), self::choose(['persil', 'basilic']));

        // Then : une nouvelle demande, jamais une correction
        $this->assertSame([[self::USER, 'lasagnes-id', null]], $this->repas->mealsPlanned());
        $this->assertSame([], $this->repas->rememberedRecipes());
        $this->assertSame([], $this->repas->remembered());
        $this->assertSame("C'est noté : lasagnes, pour 4 personnes.", $result->speech);
        $this->assertFalse($result->keepSessionOpen);
    }

    public function testARecipeSaidToAnUnknownProductSearchesThePhraseInTheRecipesOnce(): void
    {
        // Given : « hachis parmentier » inconnu comme produit ; une recette porte ce nom
        $this->repas->ownsRecipe('hachis-id', 'Hachis parmentier');
        $this->repas->willPlanMeal($this->plannedMeal('Hachis parmentier', 4));
        $state = SpokenDialogueState::choose('hachis parmentier', [], 'hachis parmentier');

        // When : « c'est une recette »
        $result = $this->answer(AlexaTestRequest::chooseKind('une recette', 'meal'), $state);

        // Then : cherchée dans les recettes avec la phrase d'origine, planifiée pour le nombre du compte
        $this->assertSame([[self::USER, 'hachis parmentier']], $this->repas->askedRecipes);
        $this->assertSame([[self::USER, 'hachis-id', null]], $this->repas->mealsPlanned());
        $this->assertSame([], $this->repas->added());
        $this->assertSame("C'est noté : hachis parmentier, pour 4 personnes.", $result->speech);
    }

    public function testNoRecipeAfterAProductSwitchMeansNothingMoreToAskAndTheSessionClosesWithoutAState(): void
    {
        // When : « une recette » mais aucune recette ne ressemble
        $result = $this->answer(AlexaTestRequest::chooseKind('une recette', 'meal'), SpokenDialogueState::choose('xyz', [], 'xyz'));

        // Then : les deux côtés sont essayés, plus d'issue
        $this->assertSame("Je ne trouve rien qui s'appelle xyz.", $result->speech);
        $this->assertNull($result->nextState);
        $this->assertFalse($result->keepSessionOpen);
        $this->assertSame([], $this->repas->mealsPlanned());
    }

    public function testSuggestionsAfterAProductSwitchDoNotOfferTheProductAgain(): void
    {
        // Given
        $this->repas->ownsRecipe('r1', 'Tarte aux pommes');

        // When
        $result = $this->answer(AlexaTestRequest::chooseKind('une recette', 'meal'), SpokenDialogueState::choose('xyz', [], 'xyz'));

        // Then : « ou un produit ? » n'est plus proposé, et l'état retient les deux côtés essayés
        $this->assertSame('Je ne trouve pas la recette xyz. Tu veux dire tarte aux pommes ?', $result->speech);
        $this->assertTrue($result->nextState?->otherSideTried);
        $this->assertTrue($result->nextState->isMeal());
    }

    public function testAProductSaidToAnUnknownRecipeSearchesThePhraseInTheProductsOnce(): void
    {
        // Given : « hachis » inconnu comme recette ; un produit porte ce nom
        $this->repas->willResolve(new ResolvedIngredient('hachis', 'Hachis', 'Boucherie', ResolvedIngredient::SURE));

        // When : « un produit »
        $result = $this->answer(AlexaTestRequest::chooseKind('un produit', 'item'), SpokenDialogueState::chooseRecipe('hachis', [], 'hachis'));

        // Then : ajouté comme produit, rien de planifié
        $this->assertSame([[self::USER, 'hachis']], $this->repas->added());
        $this->assertSame([], $this->repas->mealsPlanned());
        $this->assertSame("C'est noté : hachis.", $result->speech);
    }

    public function testAProductWhoseNameStartsWithTheWordMealIsSearchedUnderItsWholeNameAfterTheProductSwitch(): void
    {
        // Given : « ajoute le repas pour chien », dit pour un produit : le mot est retiré de la recette (« pour chien ») mais gardé dans la phrase d'origine
        $this->repas->willResolve(new ResolvedIngredient('repas-pour-chien', 'Repas pour chien', 'Animalerie', ResolvedIngredient::SURE));
        $state = SpokenDialogueState::chooseRecipe('pour chien', [], 'pour chien', null, false, 'le repas pour chien');

        // When : « un produit »
        $result = $this->answer(AlexaTestRequest::chooseKind('un produit', 'item'), $state);

        // Then : le produit « repas pour chien » est cherché (l'article retiré) et ajouté, pas « pour chien »
        $this->assertSame([[self::USER, 'repas pour chien']], $this->repas->asked);
        $this->assertSame([[self::USER, 'repas-pour-chien']], $this->repas->added());
        $this->assertSame("C'est noté : repas pour chien.", $result->speech);
    }

    public function testANewMealSaidInTheMiddleOfAQuestionKeepsItsOriginalPhraseInTheNextQuestion(): void
    {
        // When : « ajoute le repas pour chien » à une question de produits, aucune recette ne ressemble
        $result = $this->answer(AlexaTestRequest::addItem('le repas pour chien'), self::choose(['persil']));

        // Then : « C'est un produit ? » (il n'a aucune recette) et l'état porte la phrase entière
        $this->assertSame("Tu n'as encore aucune recette dans Repas. C'est un produit ?", $result->speech);
        $this->assertSame('le repas pour chien', $result->nextState?->originalPhrase);
    }

    public function testNoProductAfterARecipeSwitchProposesTheUsualQuestionsWithoutOfferingARecipeAgain(): void
    {
        // Given : ni recette ni produit proche
        $state = SpokenDialogueState::chooseRecipe('xyz', [], 'xyz');

        // When
        $result = $this->answer(AlexaTestRequest::chooseKind('un produit', 'item'), $state);

        // Then : le dialogue d'un produit inconnu, sans « ou une recette ? » (les recettes ont déjà été cherchées)
        $this->assertSame('Je ne connais pas xyz. Je le crée ?', $result->speech);
        $this->assertTrue($result->nextState?->otherSideTried);
        $this->assertFalse($result->nextState->isMeal());
    }

    public function testNoQuestionIsAskedForAnAbandonedRecipeDialogue(): void
    {
        // When : « non » aux recettes proposées
        $result = $this->answer(AlexaTestRequest::no(), self::chooseRecipe(['gratin dauphinois']));

        // Then : jamais de création de recette
        $this->assertEquals(new AddSpokenItemResult("D'accord, je ne planifie rien.", keepSessionOpen: false, added: false), $result);
        $this->assertSame([], $this->repas->mealsPlanned());
    }

    public function testAnswerSixToTheQuestionOfAMealAlreadyInTheListChangesTheTotal(): void
    {
        // Given
        $this->repas->willChangeMealServings(ChangedMealServings::changed('Gratin', 6));
        $state = SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'Gratin'), 4);

        // When : « six »
        $result = $this->answer(AlexaTestRequest::answerServings('six', '6'), $state);

        // Then : le nouveau total (6) remplace l'ancien (4), pas une addition
        $this->assertSame([[self::USER, 'gratin-id', 6]], $this->repas->mealsChanged());
        $this->assertEquals(new AddSpokenItemResult("C'est changé : gratin, pour 6 personnes.", keepSessionOpen: false, added: true), $result);
    }

    public function testTheSameNumberOrNoOrNothingChangesNothing(): void
    {
        // Given
        $state = SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'Gratin'), 4);

        // When : « quatre » (elle s'est trompée), « non », « rien »
        $same = $this->answer(AlexaTestRequest::answerServings('quatre', '4'), $state);
        $no = $this->answer(AlexaTestRequest::no(), $state);
        $nothing = $this->answer(AlexaTestRequest::keepMeal(), $state);

        // Then
        $this->assertSame([], $this->repas->mealsChanged());
        foreach ([$same, $no, $nothing] as $result) {
            $this->assertEquals(new AddSpokenItemResult("D'accord, je ne change rien.", keepSessionOpen: false, added: false), $result);
        }
    }

    public function testTheTotalCanBeReducedAndIsSaidAsTheNewTotal(): void
    {
        // Given
        $this->repas->willChangeMealServings(ChangedMealServings::changed('Gratin', 2));

        // When : « pour deux personnes »
        $result = $this->answer(AlexaTestRequest::addItem('pour deux personnes'), SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'Gratin'), 4));

        // Then
        $this->assertSame([[self::USER, 'gratin-id', 2]], $this->repas->mealsChanged());
        $this->assertSame("C'est changé : gratin, pour 2 personnes.", $result->speech);
    }

    public function testAMealThatLeftTheListSinceTheQuestionIsSaidSo(): void
    {
        // Given
        $this->repas->willChangeMealServings(ChangedMealServings::notPlanned('Gratin'));

        // When
        $result = $this->answer(AlexaTestRequest::answerServings('six', '6'), SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'Gratin'), 4));

        // Then
        $this->assertSame("Ce repas n'est plus dans ta liste.", $result->speech);
    }

    public function testAMisunderstoodTotalIsAskedAgainOnceThenGivenUp(): void
    {
        // Given
        $state = SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'Gratin'), 4);

        // When : « oui » ne dit pas pour combien
        $first = $this->answer(AlexaTestRequest::yes(), $state);
        $second = $this->answer(AlexaTestRequest::yes(), $first->nextState ?? $state);

        // Then
        $this->assertSame(1, $first->nextState?->misses);
        $this->assertSame("Je n'ai pas compris. Le repas gratin est déjà prévu pour 4 personnes. Tu le veux pour combien, ou on ne change rien ?", $first->speech);
        $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. On laisse tomber.", keepSessionOpen: false, added: false), $second);
        $this->assertSame([], $this->repas->mealsChanged());
    }

    public function testHelpRepeatsTheQuestionOfTheTotalWithoutCountingAMiss(): void
    {
        // When
        $result = $this->answer(AlexaTestRequest::intent('AMAZON.HelpIntent'), SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'Gratin'), 4));

        // Then
        $this->assertSame(0, $result->nextState?->misses);
        $this->assertSame('Le repas gratin est déjà prévu pour 4 personnes. Tu le veux pour combien, ou on ne change rien ?', $result->speech);
    }

    public function testANewProductSaidToTheQuestionOfTheTotalAbandonsItAndAddsTheProduct(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        $result = $this->answer(AlexaTestRequest::addItem('du pain'), SpokenDialogueState::askingServings(new DialogueOption('gratin-id', 'Gratin'), 4));

        // Then
        $this->assertSame([[self::USER, 'pain']], $this->repas->added());
        $this->assertSame([], $this->repas->mealsChanged());
        $this->assertSame("C'est noté : pain.", $result->speech);
    }

    public function testASuggestionAProductQuestionMakesOffersTheRecipeOnlyOnce(): void
    {
        // Given : un produit inconnu, une proposition
        $state = SpokenDialogueState::choose('hachis', [new DialogueOption('persil-u', 'persil')], 'hachis');

        // When : la question répétée à « aide »
        $help = $this->answer(AlexaTestRequest::intent('AMAZON.HelpIntent'), $state);

        // Then
        $this->assertSame('Tu veux dire persil ? Ou un nouveau produit, ou une recette ?', $help->speech);
    }

    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————
    // Retirer ce que la liste ne porte pas : propositions tirées de la liste et aiguillage (ADR 0029 décision 12, VOX-306)
    // ———————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————

    private static function mealsToRemove(?int $servings = null, bool $otherSideTried = false, ?string $original = null): SpokenDialogueState
    {
        return SpokenDialogueState::chooseMealToRemove('gratin', [new DialogueOption('recipe-2', 'Gratin dauphinois'), new DialogueOption('recipe-3', 'Gratin de pâtes')], $servings, $otherSideTried, $original);
    }

    private static function itemsToRemove(int $quantity = 1, ?string $unitSlug = null, bool $otherSideTried = false): SpokenDialogueState
    {
        return SpokenDialogueState::proposeToRemove('des pâtes à pizza', [new DialogueOption('pate-brisee', 'Pâte brisée'), new DialogueOption('pate-sablee', 'Pâte sablée')], 'pâtes à pizza', $quantity, $unitSlug, $otherSideTried);
    }

    public function testAChosenMealIsRemovedWholeAndNothingIsRememberedNorPlanned(): void
    {
        // Given
        $this->repas->willRemoveMeal(RemovedSpokenMeal::removed('Gratin de pâtes'));

        // When : « le deuxième »
        $result = $this->answer(AlexaTestRequest::chooseOption('le deuxième', '2'), self::mealsToRemove());

        // Then : le repas de la liste est retiré en entier, rien n'est planifié, aucune phrase n'est retenue, la séance se ferme
        $this->assertSame([[self::USER, 'recipe-3', null]], $this->repas->mealsRemoved());
        $this->assertSame([], $this->repas->mealsPlanned());
        $this->assertSame([], $this->repas->rememberedRecipes());
        $this->assertEquals(new AddSpokenItemResult("C'est retiré : le repas gratin de pâtes.", keepSessionOpen: false, added: false), $result);
    }

    public function testTheServingsToTakeOffTravelWithTheState(): void
    {
        // Given
        $this->repas->willRemoveMeal(RemovedSpokenMeal::lowered('Gratin dauphinois', 2));

        // When : « le premier », pour un retrait de deux personnes
        $result = $this->answer(AlexaTestRequest::chooseOption('le premier', '1'), self::mealsToRemove(servings: 2));

        // Then
        $this->assertSame([[self::USER, 'recipe-2', 2]], $this->repas->mealsRemoved());
        $this->assertSame("C'est changé : gratin dauphinois, pour 2 personnes.", $result->speech);
    }

    public function testAChosenMealThatLeftTheListIsSaidAndNothingIsWritten(): void
    {
        // Given : l'état est une entrée non fiable ; le repas a disparu
        $this->repas->willRemoveMeal(RemovedSpokenMeal::notPlanned());

        // When
        $result = $this->answer(AlexaTestRequest::yes(), SpokenDialogueState::chooseMealToRemove('gratin', [new DialogueOption('recipe-2', 'Gratin dauphinois')]));

        // Then
        $this->assertSame("Ce repas n'est plus dans ta liste.", $result->speech);
    }

    public function testRefusingTheProposedMealsRemovesNothing(): void
    {
        // When
        $refused = $this->answer(AlexaTestRequest::no(), self::mealsToRemove());

        // Then : « je ne retire rien », pas « je ne planifie rien »
        $this->assertEquals(new AddSpokenItemResult("D'accord, je ne retire rien.", keepSessionOpen: false, added: false), $refused);
        $this->assertSame([], $this->repas->mealsRemoved());
    }

    public function testAProductAnswerToAMealQuestionSearchesTheProductsOfTheListOnceWithTheWholePhrase(): void
    {
        // Given : « enlève le repas pour chien » : rien de planifié ne ressemble ; l'utilisateur répond « un produit »
        $state = SpokenDialogueState::chooseMealToRemove('pour chien', [], null, false, 'le repas pour chien');
        $this->repas->listHas(new ListedLine('repas-chien', 'repas pour chien', 'animal', 'animal', 3.0, 'paquet', 'paquet'));
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 2.0, removed: 1.0, unitSlug: 'paquet', unitName: 'paquet'));

        // When
        $result = $this->answer(AlexaTestRequest::chooseKind('un produit', 'item'), $state);

        // Then : la phrase d'origine ENTIÈRE est cherchée dans les lignes de la liste, et le produit est retiré
        $this->assertSame([[self::USER, 'repas pour chien']], $this->repas->lookedUp);
        $this->assertSame([[self::USER, 'repas-chien']], $this->repas->removed());
        $this->assertSame("C'est retiré, il t'en reste 2 paquets dans ta liste.", $result->speech);
        $this->assertSame([], $this->repas->added());
    }

    public function testAProductAnswerThatFindsNothingEitherEndsWithoutAnyWayOut(): void
    {
        // Given : les repas viennent d'être cherchés, les produits ne ressemblent à rien non plus
        $this->repas->hasActiveList();

        // When
        $result = $this->answer(AlexaTestRequest::chooseKind('un produit', 'item'), SpokenDialogueState::chooseMealToRemove('lasagnes', []));

        // Then : « rien trouvé », plus d'issue, plus d'état, rien d'écrit
        $this->assertSame("Je ne trouve rien qui s'appelle lasagnes dans ta liste.", $result->speech);
        $this->assertNull($result->nextState);
        $this->assertFalse($result->keepSessionOpen);
        $this->assertSame([], $this->repas->removed());
    }

    public function testAMealAnswerToAProductQuestionSearchesThePlannedMealsOnce(): void
    {
        // Given : « enlève le hachis » : aucun produit ne ressemble ; l'utilisateur répond « un repas »
        $this->repas->listPlans(new ListedMeal('recipe-4', 'Pâtes à pizza maison', 4));
        $this->repas->willRemoveMeal(RemovedSpokenMeal::removed('Pâtes à pizza maison'));

        // When
        $result = $this->answer(AlexaTestRequest::chooseKind('un repas', 'meal'), self::itemsToRemove());

        // Then : la phrase d'origine est cherchée parmi les repas de la liste, le repas est retiré en entier
        $this->assertSame([[self::USER, 'pâtes à pizza']], $this->repas->lookedUpMeals);
        $this->assertSame([[self::USER, 'recipe-4', null]], $this->repas->mealsRemoved());
        $this->assertSame("C'est retiré : le repas pâtes à pizza maison.", $result->speech);
        $this->assertSame([], $this->repas->removed());
    }

    public function testAMealAnswerThatFindsNothingEitherEndsWithoutAnyWayOut(): void
    {
        // Given : aucun repas planifié ne ressemble non plus
        $this->repas->hasActiveList();

        // When
        $result = $this->answer(AlexaTestRequest::chooseKind('un repas', 'meal'), self::itemsToRemove());

        // Then : plus d'issue, plus d'état
        $this->assertSame("Je ne trouve rien qui s'appelle pâtes à pizza dans ta liste.", $result->speech);
        $this->assertNull($result->nextState);
        $this->assertSame([], $this->repas->mealsRemoved());
    }

    public function testAMealAnswerKeepsTheQuestionOpenWithTheClosestMealsWhenNoneIsSure(): void
    {
        // Given : les repas planifiés ressemblent à la phrase sans qu'aucun soit sûr
        $this->repas->listPlans(new ListedMeal('recipe-5', 'Pâtes au pesto', 2));

        // When
        $result = $this->answer(AlexaTestRequest::chooseKind('une recette', 'meal'), self::itemsToRemove());

        // Then : une question, l'état est celui d'un retrait de repas dont les produits ont déjà été cherchés
        $this->assertSame("Je ne trouve pas le repas pâtes à pizza dans ta liste. Tu veux dire pâtes au pesto ?", $result->speech);
        $state = $result->nextState;
        $this->assertNotNull($state);
        $this->assertTrue($state->isRemovalProposal());
        $this->assertTrue($state->isMeal());
        $this->assertTrue($state->otherSideTried);
    }

    public function testAChosenProposedLineIsRemovedWithTheSaidQuantityAndRemembersNothing(): void
    {
        // Given
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 1.0, removed: 2.0, unitSlug: 'paquet', unitName: 'paquet'));

        // When : « la première »
        $result = $this->answer(AlexaTestRequest::chooseOption('la première', '1'), self::itemsToRemove(2, 'paquet'));

        // Then : la ligne de la liste perd la quantité dite, rien n'est ajouté ni retenu
        $this->assertSame([[self::USER, 'pate-brisee']], $this->repas->removed());
        $this->assertSame([[2, 'paquet']], $this->repas->removedWith());
        $this->assertSame([], $this->repas->added());
        $this->assertSame([], $this->repas->remembered());
        $this->assertSame("C'est retiré, il t'en reste 1 paquet dans ta liste.", $result->speech);
    }

    public function testAProductNamedInsteadOfTheProposedLinesIsANewRequestThatRemembersNothing(): void
    {
        // Given : l'utilisateur ne dit pas une proposition mais un autre produit, à ajouter
        $this->repas->willResolve(new ResolvedIngredient('pain-u', 'pain', 'boulangerie', ResolvedIngredient::SURE));

        // When
        $this->answer(AlexaTestRequest::addItem('du pain'), self::itemsToRemove());

        // Then : un ajout ordinaire, la phrase d'origine n'est PAS retenue pour lui
        $this->assertSame([], $this->repas->remembered());
    }

    public function testAMealToRemoveSaidInTheMiddleOfAQuestionIsANewRemoval(): void
    {
        // Given
        $this->repas->listPlans(new ListedMeal('recipe-6', 'Lasagnes', 6));
        $this->repas->willRemoveMeal(RemovedSpokenMeal::lowered('Lasagnes', 4));

        // When : à la question d'un produit à retirer, « enlève le repas lasagnes pour deux personnes »
        $result = $this->answer(AlexaTestRequest::removeMeal('lasagnes', 'deux', '2'), self::itemsToRemove());

        // Then : le dialogue est abandonné, le repas est cherché dans la liste et deux personnes sont enlevées
        $this->assertSame([[self::USER, 'recipe-6', 2]], $this->repas->mealsRemoved());
        $this->assertSame("C'est changé : lasagnes, pour 4 personnes.", $result->speech);
        $this->assertNull($result->nextState);
    }

    public function testTwoUnclearAnswersToAMealProposalGiveUpWithoutRemovingAnything(): void
    {
        // When
        $first = $this->answer(AlexaTestRequest::intent('AMAZON.FallbackIntent'), self::mealsToRemove());
        $second = $this->answer(AlexaTestRequest::intent('AMAZON.FallbackIntent'), $first->nextState);

        // Then : l'état garde « retirer » et la cible entre les deux demandes
        $this->assertNotNull($first->nextState);
        $this->assertTrue($first->nextState->isRemovalProposal());
        $this->assertTrue($first->nextState->isMeal());
        $this->assertSame("Je n'ai pas compris. Tu veux dire gratin dauphinois ou gratin de pâtes ? Ou un produit ?", $first->speech);
        $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. On laisse tomber.", keepSessionOpen: false, added: false), $second);
        $this->assertSame([], $this->repas->mealsRemoved());
    }
}
