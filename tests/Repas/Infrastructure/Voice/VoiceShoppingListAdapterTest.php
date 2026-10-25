<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Infrastructure\Voice\VoiceShoppingListAdapter;
use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Application\Interface\RemovedItem;
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

    public function testItDispatchesTheRemovalAndReturnsTheResultOfTheHandler(): void
    {
        // Given
        $result = new RemovedItem(found: true, remaining: 2.0, removed: 1.0);
        $bus = new MockCommandBus()->willReturn(RemoveSpokenIngredientCommand::class, $result);

        // When
        $removed = new VoiceShoppingListAdapter($bus)->removeItem('user-id', 'pain', 3, 'kilo');

        // Then : la commande porte l'utilisateur, l'ingrédient, la quantité et l'unité dits ; le résultat est rendu tel quel
        $this->assertSame($result, $removed);
        $command = $bus->dispatched()[0];
        $this->assertInstanceOf(RemoveSpokenIngredientCommand::class, $command);
        $this->assertSame(['user-id', 'pain', 3, 'kilo'], [$command->ownerId, $command->ingredientSlug, $command->quantity, $command->unitSlug]);
    }

    public function testARemovalThatReturnsNothingIsABug(): void
    {
        // Given
        $bus = new MockCommandBus();

        // Then
        $this->expectException(LogicException::class);

        // When
        new VoiceShoppingListAdapter($bus)->removeItem('user-id', 'pain');
    }
}
