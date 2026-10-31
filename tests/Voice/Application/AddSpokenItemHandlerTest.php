<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Voice\Domain\Model\ResolvedIngredient;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Tests\Helper\VoiceShoppingListInMemory;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemHandler;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Ajouter à la liste ce que l'utilisateur vient de dire (VOX-204) : le choix de l'ingrédient passe par la vraie chaîne (adapter de `Voice`,
 * Open Host Service et classeur de `Repas`, `RepasForVoice`) sur des doubles en mémoire, la liste par son port de `Shared` en mémoire : aucune
 * base, aucun noyau. Le classement lui-même se prouve dans `Repas` (`IngredientPhraseCommandOHSTest`, `SpokenIngredientRankerTest`) et de bout
 * en bout (`AlexaWebhookApiFlowTest`).
 */
class AddSpokenItemHandlerTest extends TestCase
{
    private RepasForVoice $repas;
    private VoiceShoppingListInMemory $shoppingList;
    private AddSpokenItemHandler $handler;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
        $this->shoppingList = new VoiceShoppingListInMemory();
        $this->handler = new AddSpokenItemHandler($this->repas->resolutionPort(), $this->shoppingList, new SpokenIngredientNamer());
    }

    public function testASureChoiceIsAddedAndSaidInFewWords(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then : appelé avec l'utilisateur du jeton et la phrase, l'ingrédient trouvé est ajouté
        $this->assertSame([['user-1', 'du pain']], $this->repas->asked);
        $this->assertSame([['user-1', 'pain']], $this->shoppingList->added);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pain.", keepSessionOpen: false, added: true), $result);
    }

    #[DataProvider('guesses')]
    public function testAProbableOrUnsureChoiceSaysWhatWasPutAndWhere(string $confidence): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('tomate', 'Tomate', 'Fruits et légumes', $confidence));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'des tomates', sessionIsOpen: false));

        // Then : ajouté quand même, et dit avec son rayon
        $this->assertSame([['user-1', 'tomate']], $this->shoppingList->added);
        $this->assertSame("J'ai mis tomate, fruits et légumes.", $result->speech);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guesses(): iterable
    {
        yield 'probable' => [ResolvedIngredient::PROBABLE];
        yield 'incertain' => [ResolvedIngredient::UNSURE];
    }

    #[DataProvider('rememberedConfidences')]
    public function testASureOrProbableChoiceRetainsTheOriginalPhraseWhenToldTo(string $confidence): void
    {
        // Given : l'utilisateur répond à des propositions en nommant un autre ingrédient
        $this->repas->willResolve(new ResolvedIngredient('pomme-de-terre', 'Pomme de terre', 'Fruits et légumes', $confidence));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'de la pomme de terre', sessionIsOpen: true, rememberAs: 'des patates'));

        // Then : ajouté, la phrase d'ORIGINE est retenue, et Alexa le promet
        $this->assertSame([['user-1', 'pomme-de-terre']], $this->shoppingList->added);
        $this->assertSame([['user-1', 'des patates', 'pomme-de-terre']], $this->repas->remembered());
        $this->assertSame("C'est noté : pomme de terre. Je m'en souviendrai.", $result->speech);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rememberedConfidences(): iterable
    {
        yield 'sûr' => [ResolvedIngredient::SURE];
        yield 'probable' => [ResolvedIngredient::PROBABLE];
    }

    public function testAnUnsureChoiceNeverRetainsThePhraseNorPromisesIt(): void
    {
        // Given : deux noms qui se touchent
        $this->repas->willResolve(new ResolvedIngredient('pomme-de-terre', 'Pomme de terre', 'Fruits et légumes', ResolvedIngredient::UNSURE));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'de la pomme de terre', sessionIsOpen: true, rememberAs: 'des patates'));

        // Then : un ajout ordinaire, dit avec son rayon
        $this->assertSame([['user-1', 'pomme-de-terre']], $this->shoppingList->added);
        $this->assertSame([], $this->repas->remembered());
        $this->assertSame('J\'ai mis pomme de terre, fruits et légumes.', $result->speech);
    }

    public function testAnOrdinaryAddNeverRetainsAPhrase(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When : pas de `rememberAs` (ajout direct, ou rien n'avait été proposé)
        ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: true));

        // Then
        $this->assertSame([], $this->repas->remembered());
    }

    public function testWithoutAQuantityOneIsAddedInThePurchaseUnit(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then : une unité, aucune unité dite
        $this->assertSame([[1, null]], $this->shoppingList->addedWith);
    }

    public function testASaidQuantityGoesToTheListAndIsSaid(): void
    {
        // Given : « trois croquettes »
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: false, previousListSent: false, quantity: 3.0, addedQuantity: 3.0));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'croquettes', sessionIsOpen: false, quantity: 3));

        // Then
        $this->assertSame([[3, null]], $this->shoppingList->addedWith);
        $this->assertSame("C'est noté : 3 croquettes.", $result->speech);
    }

    public function testASaidUnitGoesToTheListAndIsSaidWhenTheIngredientKnowsIt(): void
    {
        // Given : « deux kilos de tomates », la ligne naît au kilo
        $this->repas->willResolve(new ResolvedIngredient('tomate', 'Tomate', 'Fruits et légumes', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: false, previousListSent: false, quantity: 2.0, unitSlug: 'kilo', unitName: 'kilo', addedQuantity: 2.0));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'tomates', sessionIsOpen: false, quantity: 2, unitSlug: 'kilo'));

        // Then
        $this->assertSame([[2, 'kilo']], $this->shoppingList->addedWith);
        $this->assertSame("C'est noté : 2 kilos de tomate.", $result->speech);
    }

    public function testAnUnknownUnitIsSaidAsTheUnitTheLineKeeps(): void
    {
        // Given : « deux kilos de croquettes », mais les croquettes se comptent en paquets
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: false, previousListSent: false, quantity: 2.0, unitSlug: 'paquet', unitName: 'paquet', unitSubstituted: true, addedQuantity: 2.0));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'croquettes', sessionIsOpen: false, quantity: 2, unitSlug: 'kilo'));

        // Then : jamais de kilos là où la liste compte des paquets
        $this->assertSame("C'est noté : 2 paquets de croquette.", $result->speech);
    }

    public function testAnIngredientAlreadyInTheListSaysTheTotal(): void
    {
        // Given : le pain est déjà dans la liste, 2 maintenant
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: false, previousListSent: false, quantity: 2.0, lineExisted: true));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste.", $result->speech);
        $this->assertTrue($result->added);
    }

    public function testAnExistingLineKeepsItsUnitInTheTotal(): void
    {
        // Given : « un paquet de croquettes » de plus, 3 paquets maintenant
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: false, previousListSent: false, quantity: 3.0, unitSlug: 'paquet', unitName: 'paquet', lineExisted: true));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'croquettes', sessionIsOpen: false, unitSlug: 'paquet'));

        // Then
        $this->assertSame("C'est ajouté, tu en as maintenant 3 paquets dans ta liste.", $result->speech);
    }

    public function testTheTotalIsAlsoSaidWhenThePhraseIsRememberedAfterACorrection(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pomme-de-terre', 'Pomme de terre', 'Fruits et légumes', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: false, previousListSent: false, quantity: 2.0, lineExisted: true));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'pomme de terre', sessionIsOpen: false, rememberAs: 'des patates'));

        // Then : le total remplace « C'est noté », la promesse reste
        $this->assertSame("C'est ajouté, tu en as maintenant 2 dans ta liste. Je m'en souviendrai.", $result->speech);
        $this->assertSame([['user-1', 'des patates', 'pomme-de-terre']], $this->repas->remembered());
    }

    public function testAUnitLeftInTheItemSlotIsRemovedFromThePhraseAndUsedAsTheSaidUnit(): void
    {
        // Given : Alexa a mis « paquets de croquettes » dans le créneau `item`, sans créneau `unit`
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE));

        // When
        ($this->handler)(new AddSpokenItemCommand('user-1', 'paquets de croquettes', sessionIsOpen: false));

        // Then : l'ingrédient cherché est « croquettes », l'unité dite « paquet », une unité
        $this->assertSame([['user-1', 'croquettes']], $this->repas->asked);
        $this->assertSame([[1, 'paquet']], $this->shoppingList->addedWith);
    }

    public function testANumberLeftInTheItemSlotIsRemovedFromThePhraseAndUsedAsTheSaidQuantity(): void
    {
        // Given : Amazon a mis « 10 croquettes » dans le créneau `item`, `quantity` vide (essai réel du 2026-10-25)
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE));

        // When
        ($this->handler)(new AddSpokenItemCommand('user-1', '10 croquettes', sessionIsOpen: false));

        // Then : l'ingrédient cherché est « croquettes », la quantité dite 10, aucune unité
        $this->assertSame([['user-1', 'croquettes']], $this->repas->asked);
        $this->assertSame([[10, null]], $this->shoppingList->addedWith);
    }

    public function testANumberAndAUnitLeftInTheItemSlotAreBothUsed(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE));

        // When
        ($this->handler)(new AddSpokenItemCommand('user-1', 'deux paquets de croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame([['user-1', 'croquettes']], $this->repas->asked);
        $this->assertSame([[2, 'paquet']], $this->shoppingList->addedWith);
    }

    public function testTheQuantitySlotWinsOverANumberLeftInTheItemSlot(): void
    {
        // Given : la phrase telle quelle (« 3 croquettes ») a déjà été associée à la croquette, sinon rien ne la reconnaîtrait
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE))
            ->remembers('user-1', SpokenIngredientRanker::phraseKey('3 croquettes'), 'croquette');

        // When : `quantity` dit 4, le créneau `item` garde un « 3 » : la phrase reste telle quelle
        ($this->handler)(new AddSpokenItemCommand('user-1', '3 croquettes', sessionIsOpen: false, quantity: 4));

        // Then
        $this->assertSame([['user-1', '3 croquettes']], $this->repas->asked);
        $this->assertSame([[4, null]], $this->shoppingList->addedWith);
    }

    public function testAnUnknownProductKeepsTheNumberLeftInTheItemSlotAsTheQuantityOfTheDialogue(): void
    {
        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', '3 ciboulettes', sessionIsOpen: false));

        // Then : le nom à créer est sans le nombre, la quantité part dans l'état du dialogue
        $this->assertSame([['user-1', 'ciboulettes']], $this->repas->asked);
        $this->assertSame(3, $result->nextState?->quantity);
        $this->assertSame('ciboulettes', $result->nextState->phrase);
    }

    public function testTheUnitSlotWinsOverAUnitLeftInTheItemSlot(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('croquette', 'Croquette', 'Animal', ResolvedIngredient::SURE));

        // When : Alexa a rempli `unit` (kilo) ET laissé « paquets de » dans `item`
        ($this->handler)(new AddSpokenItemCommand('user-1', 'paquets de croquettes', sessionIsOpen: false, quantity: 2, unitSlug: 'kilo'));

        // Then
        $this->assertSame([[2, 'kilo']], $this->shoppingList->addedWith);
        $this->assertSame([['user-1', 'croquettes']], $this->repas->asked);
    }

    public function testAnUnknownProductKeepsTheSaidQuantityAndUnitInTheDialogue(): void
    {
        // When : « trois paquets de ciboulette », inconnue
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'ciboulette', sessionIsOpen: false, quantity: 3, unitSlug: 'paquet'));

        // Then : rien n'est écrit, l'état garde la quantité et l'unité pour la suite
        $this->assertSame([], $this->shoppingList->added);
        $this->assertSame([3, 'paquet'], [$result->nextState->quantity, $result->nextState->spokenUnitSlug]);
    }

    public function testIngredientsThatTieKeepTheSaidQuantityAndUnitInTheDialogue(): void
    {
        // Given
        $this->repas->willTie([
            new DialogueOption('tomate-fl', 'Tomate', 'fruit-et-legume', 'Fruit & légume'),
            new DialogueOption('tomate-sg', 'Tomate', 'surgele', 'Surgelé'),
        ]);

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'kilos de tomates', sessionIsOpen: false, quantity: 2));

        // Then : la phrase de l'état est celle sans l'unité, qui voyage à part
        $this->assertSame('tomates', $result->nextState->phrase);
        $this->assertSame([2, 'kilo'], [$result->nextState->quantity, $result->nextState->spokenUnitSlug]);
    }

    public function testANewListIsSaid(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: false));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", $result->speech);
    }

    public function testThePreviousListSentToTheDriveIsSaidToo(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain. Ta liste précédente est partie au drive.", $result->speech);
    }

    public function testAnUnknownProductWithNothingCloseAsksWhetherToCreateItAndWritesNothing(): void
    {
        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'de la ciboulette', sessionIsOpen: false));

        // Then : rien n'est écrit, le port de la liste n'est même pas appelé ; la séance reste ouverte même en appel direct
        $this->assertSame([['user-1', 'de la ciboulette']], $this->repas->asked);
        $this->assertSame([], $this->shoppingList->added);
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette');
        $this->assertEquals(new AddSpokenItemResult('Je ne connais pas ciboulette. Je le crée ? Ou c\'est une recette ?', keepSessionOpen: true, added: false, nextState: $state, reprompt: 'Je ne connais pas ciboulette. Je le crée ? Ou c\'est une recette ?'), $result);
    }

    public function testAnUnknownProductWithCloseIngredientsProposesThemAndTheNewProduct(): void
    {
        // Given : trois sirops à l'utilisateur, la phrase n'en désigne aucun tout seul
        $this->repas->owns('sirop-rouge-u', 'Sirop fruit rouge')->owns('sirop-fraise-u', 'Sirop fraise vanille')->owns('sirop-grenadine-u', 'Sirop grenadine')->owns('farine-u', 'Farine');

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'sirop de menthe', sessionIsOpen: true));

        // Then : « de quoi tu parles ? », les trois propositions dans l'ordre du classeur, l'état porte les slugs, la phrase et le nom à créer
        $this->assertSame('Je ne connais pas sirop de menthe. Tu veux dire sirop grenadine, sirop fraise vanille ou sirop fruit rouge ? Ou un nouveau produit, ou une recette ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
        $this->assertSame([], $this->shoppingList->added);
        $this->assertSame('Tu veux dire sirop grenadine, sirop fraise vanille ou sirop fruit rouge ? Ou un nouveau produit, ou une recette ?', $result->reprompt);
        $this->assertSame(DialogueStep::CHOOSE, $result->nextState?->step);
        $this->assertEquals(
            [new DialogueOption('sirop-grenadine-u', 'Sirop grenadine'), new DialogueOption('sirop-fraise-u', 'Sirop fraise vanille'), new DialogueOption('sirop-rouge-u', 'Sirop fruit rouge')],
            $result->nextState->options,
        );
        $this->assertSame(['sirop de menthe', 'sirop de menthe'], [$result->nextState->phrase, $result->nextState->name]);
    }

    public function testNoMoreThanThreeProposalsAreKeptWhateverTheyAre(): void
    {
        // When : cinq propositions, que le classeur n'enverrait pas (il en rend trois)
        $state = SpokenDialogueState::choose(
            'de la ciboulette',
            array_map(static fn(int $i): DialogueOption => new DialogueOption("s$i-u", "s$i"), range(1, 5)),
            'ciboulette',
        );

        // Then
        $this->assertCount(3, $state->options);
    }

    public function testAKnownProductIsNeverProposed(): void
    {
        // Given : le pain existe, et un autre ingrédient qui lui ressemble aussi
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE))->owns('pain-de-mie-u', 'Pain de mie');

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then : ajouté, aucune proposition
        $this->assertSame([['user-1', 'du pain']], $this->repas->asked);
        $this->assertNull($result->nextState);
    }

    public function testAPhraseThatCannotBecomeANameIsAskedAgainWithoutADialogue(): void
    {
        // When : que des chiffres, puis un nom beaucoup trop long
        $digits = ($this->handler)(new AddSpokenItemCommand('user-1', '12', sessionIsOpen: false));
        $tooLong = ($this->handler)(new AddSpokenItemCommand('user-1', str_repeat('a', 70), sessionIsOpen: true));

        // Then : « Je n'ai pas compris. Que veux-tu ajouter ? », la séance reste ouverte, aucun état, rien n'est écrit
        foreach ([$digits, $tooLong] as $result) {
            $this->assertEquals(new AddSpokenItemResult("Je n'ai pas compris. Que veux-tu ajouter ?", keepSessionOpen: true, added: false, reprompt: 'Que veux-tu ajouter ?'), $result);
        }
        $this->assertSame([], $this->shoppingList->added);
    }

    public function testInAnOpenSessionTheAnswerAsksForMoreAndTheSessionStaysOpen(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        $found = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: true));

        // Then
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pain.", keepSessionOpen: true, added: true), $found);
    }

    public function testIngredientsThatTieAreNotGuessedButAskedByDepartment(): void
    {
        // Given : « tomate » en fruit et légume et en surgelé
        $this->repas->willTie([
            new DialogueOption('tomate-fl', 'Tomate', 'fruit-et-legume', 'Fruit & légume'),
            new DialogueOption('tomate-sg', 'Tomate', 'surgele', 'Surgelé'),
        ]);

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'des tomates', sessionIsOpen: false));

        // Then : rien n'est ajouté ni retenu, la question du rayon (sans « nouveau produit »), la séance reste ouverte, l'état porte les rayons
        $this->assertSame([], $this->shoppingList->added);
        $this->assertSame([], $this->repas->remembered());
        $this->assertSame('Tomate : en fruit et légume ou en surgelé ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertFalse($result->added);
        $this->assertSame($result->speech, $result->reprompt);
        $this->assertTrue($result->nextState->isTieBreak());
        $this->assertSame(['tomate-fl', 'surgele'], [$result->nextState->options[0]->slug, $result->nextState->options[1]->departmentSlug]);
        $this->assertSame('des tomates', $result->nextState->phrase);
    }

    public function testADirectCallClosesTheSessionAndDoesNotAskForMore(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertFalse($result->keepSessionOpen);
        $this->assertStringNotContainsString('Autre chose', $result->speech);
    }


    public function testTheNameSpokenBackIsLowercasedAndBounded(): void
    {
        // Given
        $this->repas->willResolve(new ResolvedIngredient('creme-fraiche', 'Crème Fraîche ÉPAISSE', 'Produits LAITIERS', ResolvedIngredient::PROBABLE));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'Crème', sessionIsOpen: false));
        $unknown = ($this->handler)(new AddSpokenItemCommand('user-1', 'ZÉBULON ' . str_repeat('Z', 40), sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai mis crème fraîche épaisse, produits laitiers.", $result->speech);
        $this->assertSame('Je ne connais pas zébulon ' . str_repeat('z', 40) . '. Je le crée ? Ou c\'est une recette ?', $unknown->speech);
    }

    public function testAFailureOfTheListPortIsNotCaughtSoTheTransactionIsCancelled(): void
    {
        // Given : l'ingrédient a disparu entre le choix et l'ajout
        $this->repas->willResolve(new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willThrow(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then
        $this->expectException(DomainException::class);

        // When
        ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));
    }
}
