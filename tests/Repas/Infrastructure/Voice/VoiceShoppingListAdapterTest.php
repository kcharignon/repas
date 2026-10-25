<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Infrastructure\Voice\VoiceShoppingListAdapter;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'adaptateur seulement (bus de commandes simulé) : commande construite, résultat renvoyé tel quel, erreur de domaine propagée.
 * Le parcours avec les vrais bus est dans `VoiceShoppingListFlowTest`.
 */
class VoiceShoppingListAdapterTest extends TestCase
{
    public function testItDispatchesTheCommandAndReturnsTheResultOfTheHandler(): void
    {
        // Given
        $result = new AddedItem(listCreated: true, previousListSent: true);
        $bus = new MockCommandBus()->willReturn(AddSpokenIngredientCommand::class, $result);

        // When
        $added = new VoiceShoppingListAdapter($bus)->addItem('user-id', 'pain');

        // Then
        $this->assertSame($result, $added);
        $this->assertCount(1, $bus->dispatched());
        $command = $bus->dispatched()[0];
        $this->assertInstanceOf(AddSpokenIngredientCommand::class, $command);
        $this->assertSame('user-id', $command->ownerId);
        $this->assertSame('pain', $command->ingredientSlug);
        $this->assertNotSame('', $command->newShoppingListId);
    }

    public function testEachCallGetsItsOwnIdentifierForTheListItMayCreate(): void
    {
        // Given
        $bus = new MockCommandBus()->willReturn(AddSpokenIngredientCommand::class, new AddedItem(false, false));
        $adapter = new VoiceShoppingListAdapter($bus);

        // When
        $adapter->addItem('user-id', 'pain');
        $adapter->addItem('user-id', 'pain');

        // Then
        $this->assertNotSame($bus->dispatched()[0]->newShoppingListId, $bus->dispatched()[1]->newShoppingListId);
    }

    public function testADomainErrorOfTheHandlerIsNotHidden(): void
    {
        // Given
        $bus = new MockCommandBus()->willThrow(AddSpokenIngredientCommand::class, IngredientException::notFound('pain'));

        // Then
        $this->expectExceptionObject(IngredientException::notFound('pain'));

        // When
        new VoiceShoppingListAdapter($bus)->addItem('user-id', 'pain');
    }

    public function testAHandlerThatReturnsNothingIsABug(): void
    {
        // Given
        $bus = new MockCommandBus();

        // Then
        $this->expectException(LogicException::class);

        // When
        new VoiceShoppingListAdapter($bus)->addItem('user-id', 'pain');
    }
}
