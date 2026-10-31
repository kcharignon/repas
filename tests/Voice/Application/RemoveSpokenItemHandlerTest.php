<?php

namespace Repas\Tests\Voice\Application;

use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemCommand;
use Repas\Voice\Application\Command\RemoveSpokenItem\RemoveSpokenItemHandler;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\DialogueStep;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Model\RemovedItem;
use Repas\Voice\Domain\Model\SpokenDialogueState;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Retirer ce que l'utilisateur vient de dire (ADR 0029 décision 12, VOX-304), avec les vrais adapters du contexte et les vrais Open Host Services de `Repas` sur ses doubles en mémoire (`RepasForVoice`) : aucune base, aucun noyau.
 * La recherche dans la liste et le retrait se prouvent dans `Repas` (`ActiveListLookupCommandOHSTest`, `ShoppingListItemCommandOHSFlowTest`) et de bout en
 * bout (`AlexaWebhookApiFlowTest`).
 */
class RemoveSpokenItemHandlerTest extends TestCase
{
    private RepasForVoice $repas;
    private RemoveSpokenItemHandler $handler;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
        $this->handler = new RemoveSpokenItemHandler($this->repas->lineLookupPort(), $this->repas->itemsPort(), new SpokenIngredientNamer());
    }

    private static function line(string $slug = 'croquette', string $name = 'croquette', string $departmentSlug = 'animal', string $departmentName = 'animal', float $quantity = 3.0, string $unitSlug = 'paquet', string $unitName = 'paquet'): ListedLine
    {
        return new ListedLine($slug, $name, $departmentSlug, $departmentName, $quantity, $unitSlug, $unitName);
    }

    public function testRemovesOneFromTheFoundLineAndSaysWhatIsLeft(): void
    {
        // Given : trois paquets de croquettes dans la liste
        $this->repas->listHas(self::line());
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 2.0, removed: 1.0, unitSlug: 'paquet', unitName: 'paquet'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des croquettes', sessionIsOpen: false));

        // Then : un retiré, il en reste 2 paquets ; la séance se ferme (appel direct), rien ne la garde ouverte
        $this->assertSame([['user-1', 'des croquettes']], $this->repas->lookedUp);
        $this->assertSame([['user-1', 'croquette']], $this->repas->removed());
        $this->assertSame([[1, null]], $this->repas->removedWith());
        $this->assertEquals(new AddSpokenItemResult("C'est retiré, il t'en reste 2 paquets dans ta liste.", keepSessionOpen: false, added: false), $result);
    }

    public function testRemovingFromTheListOfATypeSaysWhichList(): void
    {
        // Given : trois paquets dans la Boucherie, un seul retiré (ADR 0036, LST-008)
        $this->repas->listHas(new ListedLine('lardon', 'lardon', 'viande', 'viande', 3.0, 'paquet', 'paquet', 'Boucherie'));
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 2.0, removed: 1.0, unitSlug: 'paquet', unitName: 'paquet', listName: 'Boucherie'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'du lardon', sessionIsOpen: false));

        // Then
        $this->assertSame("C'est retiré de boucherie, il t'en reste 2 paquets.", $result->speech);
    }

    public function testAProductCarriedByTwoListsIsRemovedWithoutAskingWhich(): void
    {
        // Given : un seul produit, une ligne dans la liste principale et une dans Boucherie
        $this->repas->listHas(new ListedLine('lardon', 'lardon', 'viande', 'viande', 1.0, 'paquet', 'paquet'));
        $this->repas->listHas(new ListedLine('lardon', 'lardon', 'viande', 'viande', 2.0, 'paquet', 'paquet', 'Boucherie'));
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 0.0, removed: 1.0, unitSlug: 'paquet', unitName: 'paquet'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'du lardon', sessionIsOpen: false));

        // Then : ce n'est pas un départage, le retrait est demandé une fois (c'est la liste de `Repas` qui est choisie)
        $this->assertNull($result->nextState);
        $this->assertSame([['user-1', 'lardon']], $this->repas->removed());
    }

    public function testSaysTheLineIsGoneWhenNothingIsLeft(): void
    {
        // Given
        $this->repas->listHas(self::line(quantity: 1.0));
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 0.0, removed: 1.0, unitSlug: 'paquet', unitName: 'paquet'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame("C'est retiré : croquette. Il n'y en a plus dans ta liste.", $result->speech);
    }

    public function testTheSaidQuantityAndUnitAreRemoved(): void
    {
        // Given
        $this->repas->listHas(self::line());
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 1.0, removed: 2.0, unitSlug: 'paquet', unitName: 'paquet'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'croquettes', sessionIsOpen: false, quantity: 2, unitSlug: 'paquet'));

        // Then
        $this->assertSame([[2, 'paquet']], $this->repas->removedWith());
        $this->assertSame("C'est retiré, il t'en reste 1 paquet dans ta liste.", $result->speech);
    }

    public function testANumberAndAUnitLeftInTheItemSlotAreReadLikeForTheAdd(): void
    {
        // Given : Amazon a laissé « 2 paquets de croquettes » dans le créneau `item`
        $this->repas->listHas(self::line());

        // When
        ($this->handler)(new RemoveSpokenItemCommand('user-1', '2 paquets de croquettes', sessionIsOpen: false));

        // Then : on cherche « croquettes », on retire 2 paquets
        $this->assertSame([['user-1', 'croquettes']], $this->repas->lookedUp);
        $this->assertSame([[2, 'paquet']], $this->repas->removedWith());
    }

    public function testWithoutAnActiveListNothingIsWrittenAndNoListIsSaid(): void
    {
        // Given : le double répond « pas de liste » par défaut
        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("Tu n'as pas de liste en cours.", $result->speech);
        $this->assertSame([], $this->repas->removed());
    }

    public function testASentListIsSaidToBeGoneToTheDriveNotAbsentAndNothingIsWritten(): void
    {
        // Given : la seule liste de l'utilisateur est partie au drive (elle n'est ni lue ni modifiée)
        $this->repas->hasSentList();

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then : une phrase vraie et courte, pas « Tu n'as pas de liste en cours. »
        $this->assertSame('Ta liste est déjà partie au drive.', $result->speech);
        $this->assertSame([], $this->repas->removed());
        $this->assertNull($result->nextState);
    }

    public function testAProductThatIsNotInTheListIsSaidWithItsNameAndNothingIsWritten(): void
    {
        // Given : l'ingrédient est connu (« croquette ») mais pas dans la liste
        $this->repas->hasActiveList()->owns('croquette', 'croquette', 'animal');

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame("Il n'y a pas de croquette dans ta liste.", $result->speech);
        $this->assertSame([], $this->repas->removed());
        $this->assertNull($result->nextState);
    }

    public function testAnUnknownProductAsksIfItIsAMealAndNothingIsRemovedOrCreated(): void
    {
        // Given : rien ne ressemble à la phrase, ni dans la liste ni parmi les ingrédients
        $this->repas->hasActiveList();

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'de la ciboulette', sessionIsOpen: false));

        // Then : « pas de ciboulette », jamais « de quoi tu parles ? » ; la seule issue est l'autre type ; rien d'écrit
        $this->assertSame("Il n'y a pas de ciboulette dans ta liste. C'est un repas ?", $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertSame([], $this->repas->removed());
        $state = $result->nextState;
        $this->assertNotNull($state);
        $this->assertTrue($state->isRemovalProposal());
        $this->assertSame([[], false, false, 'ciboulette'], [$state->options, $state->isTieBreak(), $state->otherSideTried, $state->name]);
    }

    public function testAnUnknownProductProposesTheLinesOfTheListThatLookLikeIt(): void
    {
        // Given : la phrase ne désigne aucun ingrédient, deux lignes de la liste lui ressemblent
        $this->repas->listHas(self::line('pate-brisee', 'Pâte brisée', 'frais', 'frais'))->listHas(self::line('pate-sablee', 'Pâte sablée', 'frais', 'frais'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des pâtes à pizza', sessionIsOpen: false, quantity: 2, unitSlug: 'paquet'));

        // Then : les lignes DE LA LISTE, sans rayon (ce n'est pas un départage), l'issue vers les repas ; la quantité et l'unité dites voyagent
        $this->assertSame("Il n'y a pas de pâtes à pizza dans ta liste. Tu veux dire pâte brisée ou pâte sablée ? Ou un repas ?", $result->speech);
        $state = $result->nextState;
        $this->assertNotNull($state);
        $this->assertEquals([new DialogueOption('pate-brisee', 'Pâte brisée'), new DialogueOption('pate-sablee', 'Pâte sablée')], $state->options);
        $this->assertSame([true, false, 2, 'paquet'], [$state->isRemovalProposal(), $state->isTieBreak(), $state->quantity, $state->spokenUnitSlug]);
        $this->assertNull($state->rememberablePhrase());
        $this->assertSame([], $this->repas->removed());
    }

    public function testOnceTheMealsHaveBeenSearchedNothingMoreIsOfferedAndTheSessionStaysAsItWas(): void
    {
        // Given : l'utilisateur a répondu « un produit » à une question de repas ; rien ne ressemble à la phrase
        $this->repas->hasActiveList();

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'le hachis', sessionIsOpen: false, otherSideTried: true));

        // Then : plus d'issue, une affirmation seule, la séance se ferme
        $this->assertSame("Je ne trouve rien qui s'appelle hachis dans ta liste.", $result->speech);
        $this->assertNull($result->nextState);
        $this->assertFalse($result->keepSessionOpen);
    }

    public function testOnceTheMealsHaveBeenSearchedTheProposalsHaveNoOtherWayOut(): void
    {
        // Given
        $this->repas->listHas(self::line('pate-brisee', 'Pâte brisée', 'frais', 'frais'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des pâtes à pizza', sessionIsOpen: false, otherSideTried: true));

        // Then : la proposition, sans « ou un repas ? »
        $this->assertSame("Il n'y a pas de pâtes à pizza dans ta liste. Tu veux dire pâte brisée ?", $result->speech);
        $this->assertTrue($result->nextState?->otherSideTried);
    }

    public function testAnIngredientStartingWithAVowelUsesAnApostrophe(): void
    {
        // Given
        $this->repas->hasActiveList()->owns('ail', 'ail', 'frais');

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'de l\'ail', sessionIsOpen: false));

        // Then
        $this->assertSame("Il n'y a pas d'ail dans ta liste.", $result->speech);
    }

    public function testAPhraseWithoutLettersIsNotUnderstoodWhenNothingIsKnown(): void
    {
        // Given
        $this->repas->hasActiveList();

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', '12', sessionIsOpen: false));

        // Then
        $this->assertSame("Je n'ai pas compris.", $result->speech);
    }

    public function testTheLineMayHaveDisappearedBetweenTheReadingAndTheWriting(): void
    {
        // Given : la lecture trouve la ligne, l'écriture ne la trouve plus
        $this->repas->listHas(self::line());
        $this->repas->willRemove(new RemovedItem(found: false));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame("Il n'y a pas de croquette dans ta liste.", $result->speech);
    }

    public function testInAnOpenSessionTheAnswerIsAStatementThatLeavesTheSessionOpenWithoutAQuestion(): void
    {
        // Given
        $this->repas->listHas(self::line('pain', 'pain', 'boulangerie', 'boulangerie', 2.0, 'unite', 'unité'));
        $this->repas->willRemove(new RemovedItem(found: true, remaining: 1.0, removed: 1.0));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'du pain', sessionIsOpen: true));

        // Then : « C'est retiré, il t'en reste 1 dans ta liste. », séance ouverte, jamais de relance
        $this->assertSame("C'est retiré, il t'en reste 1 dans ta liste.", $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertNull($result->reprompt);
        $this->assertNull($result->nextState);
    }

    public function testTwoLinesThatTieAskForTheDepartmentAndRemoveNothing(): void
    {
        // Given : « tomate » en fruit et légume ET en surgelé, toutes deux dans la liste
        $this->repas
            ->listHas(self::line('tomate', 'tomate', 'fruit-et-legume', 'fruit et légume', 2.0, 'unite', 'unité'))
            ->listHas(self::line('tomate-surgelee', 'tomate', 'surgele', 'surgelé', 1.0, 'unite', 'unité'));

        // When
        $result = ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des tomates', sessionIsOpen: false, quantity: 2));

        // Then : la question du rayon, la séance reste ouverte avec son reprompt, l'état dit « retirer » et garde la quantité
        $this->assertSame('Tomate : en fruit et légume ou en surgelé ?', $result->speech);
        $this->assertTrue($result->keepSessionOpen);
        $this->assertSame('Tomate : en fruit et légume ou en surgelé ?', $result->reprompt);
        $this->assertFalse($result->added);
        $this->assertSame([], $this->repas->removed());
        $state = $result->nextState;
        $this->assertInstanceOf(SpokenDialogueState::class, $state);
        $this->assertSame([DialogueStep::CHOOSE, true, true, 2, 'des tomates'], [$state->step, $state->isRemoval(), $state->isTieBreak(), $state->quantity, $state->phrase]);
        $this->assertEquals(
            [new DialogueOption('tomate', 'tomate', 'fruit-et-legume', 'fruit et légume'), new DialogueOption('tomate-surgelee', 'tomate', 'surgele', 'surgelé')],
            $state->options,
        );
    }

    public function testAFailureOfThePortIsNotCaught(): void
    {
        // Given : la commande doit échouer en entier, le bus est transactionnel
        $this->repas->listHas(self::line());
        $this->repas->willFailToWriteTheList(new class('Quantité invalide') extends DomainException {
        });

        // Then
        $this->expectException(DomainException::class);

        // When
        ($this->handler)(new RemoveSpokenItemCommand('user-1', 'des croquettes', sessionIsOpen: false));
    }
}
