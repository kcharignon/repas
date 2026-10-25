<?php

namespace Repas\Tests\Voice\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\SpokenIngredientResolverInMemory;
use Repas\Tests\Helper\VoiceShoppingListInMemory;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemCommand;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemHandler;
use Repas\Voice\Application\Command\AddSpokenItem\AddSpokenItemResult;

/**
 * Ajouter à la liste ce que l'utilisateur vient de dire (VOX-204), avec les deux ports de `Shared` en mémoire : aucune base, aucun noyau.
 * Le choix de l'ingrédient et la liste elle-même se prouvent dans `Repas` (`SpokenIngredientResolverFlowTest`, `VoiceShoppingListFlowTest`) et
 * de bout en bout (`AlexaWebhookApiFlowTest`).
 */
class AddSpokenItemHandlerTest extends TestCase
{
    private SpokenIngredientResolverInMemory $resolver;
    private VoiceShoppingListInMemory $shoppingList;
    private AddSpokenItemHandler $handler;

    protected function setUp(): void
    {
        $this->resolver = new SpokenIngredientResolverInMemory();
        $this->shoppingList = new VoiceShoppingListInMemory();
        $this->handler = new AddSpokenItemHandler($this->resolver, $this->shoppingList);
    }

    public function testASureChoiceIsAddedAndSaidInFewWords(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then : appelé avec l'utilisateur du jeton et la phrase, l'ingrédient trouvé est ajouté
        $this->assertSame([['user-1', 'du pain']], $this->resolver->asked);
        $this->assertSame([['user-1', 'pain']], $this->shoppingList->added);
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pain.", keepSessionOpen: false, added: true), $result);
    }

    #[DataProvider('guesses')]
    public function testAProbableOrUnsureChoiceSaysWhatWasPutAndWhere(string $confidence): void
    {
        // Given
        $this->resolver->willResolve('des tomates', new ResolvedIngredient('tomate', 'Tomate', 'Fruits et légumes', $confidence));

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
        yield 'confiance inconnue' => ['whatever'];
    }

    public function testANewListIsSaid(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: false));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", $result->speech);
    }

    public function testThePreviousListSentToTheDriveIsSaidToo(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willReturn(new AddedItem(listCreated: true, previousListSent: true));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai commencé une nouvelle liste avec pain. Ta liste précédente est partie au drive.", $result->speech);
    }

    public function testAnItemThatCannotBeFoundIsNotAddedAndSaysSo(): void
    {
        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'xyz', sessionIsOpen: false));

        // Then : rien n'est écrit, le port de la liste n'est même pas appelé
        $this->assertSame([['user-1', 'xyz']], $this->resolver->asked);
        $this->assertSame([], $this->shoppingList->added);
        $this->assertEquals(new AddSpokenItemResult('Je ne trouve pas xyz dans tes ingrédients.', keepSessionOpen: false, added: false), $result);
    }

    public function testInAnOpenSessionTheAnswerAsksForMoreAndTheSessionStaysOpen(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        $found = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: true));
        $notFound = ($this->handler)(new AddSpokenItemCommand('user-1', 'xyz', sessionIsOpen: true));

        // Then
        $this->assertEquals(new AddSpokenItemResult("C'est noté : pain. Autre chose ?", keepSessionOpen: true, added: true), $found);
        $this->assertEquals(new AddSpokenItemResult('Je ne trouve pas xyz dans tes ingrédients. Autre chose ?', keepSessionOpen: true, added: false), $notFound);
    }

    public function testADirectCallClosesTheSessionAndDoesNotAskForMore(): void
    {
        // Given
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertFalse($result->keepSessionOpen);
        $this->assertStringNotContainsString('Autre chose', $result->speech);
    }

    public function testTheNameSpokenBackIsLowercasedAndBounded(): void
    {
        // Given
        $this->resolver->willResolve('Crème', new ResolvedIngredient('creme-fraiche', 'Crème Fraîche ÉPAISSE', 'Produits LAITIERS', ResolvedIngredient::PROBABLE));

        // When
        $result = ($this->handler)(new AddSpokenItemCommand('user-1', 'Crème', sessionIsOpen: false));
        $notFound = ($this->handler)(new AddSpokenItemCommand('user-1', str_repeat('Z', 100), sessionIsOpen: false));

        // Then
        $this->assertSame("J'ai mis crème fraîche épaisse, produits laitiers.", $result->speech);
        $this->assertSame('Je ne trouve pas ' . str_repeat('z', 60) . ' dans tes ingrédients.', $notFound->speech);
    }

    public function testAFailureOfTheListPortIsNotCaughtSoTheTransactionIsCancelled(): void
    {
        // Given : l'ingrédient a disparu entre le choix et l'ajout
        $this->resolver->willResolve('du pain', new ResolvedIngredient('pain', 'Pain', 'Boulangerie', ResolvedIngredient::SURE));
        $this->shoppingList->willThrow(new class('INGREDIENT_NOT_FOUND', 404) extends DomainException {
        });

        // Then
        $this->expectException(DomainException::class);

        // When
        ($this->handler)(new AddSpokenItemCommand('user-1', 'du pain', sessionIsOpen: false));
    }
}
