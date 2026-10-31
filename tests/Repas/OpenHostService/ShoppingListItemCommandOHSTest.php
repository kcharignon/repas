<?php

namespace Repas\Tests\Repas\OpenHostService;

use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Application\OpenHostService\ShoppingListItemCommandOHS;
use Repas\Repas\Domain\Model\AddedItem;
use Repas\Repas\Domain\Model\RemovedItem;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'adaptateur seulement (bus de commandes simulé) : commande construite, résultat renvoyé tel quel, erreur de domaine propagée.
 * Le parcours avec les vrais bus est dans `ShoppingListItemCommandOHSFlowTest`.
 */
class ShoppingListItemCommandOHSTest extends TestCase
{
    public function testItDispatchesTheCommandAndReturnsTheResultOfTheHandler(): void
    {
        // Given
        $result = new AddedItem(listCreated: true, previousListSent: true);
        $bus = new MockCommandBus()->willReturn(AddSpokenIngredientCommand::class, $result);

        // When
        $added = new ShoppingListItemCommandOHS($bus)->addItem('user-id', 'pain');

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
        $adapter = new ShoppingListItemCommandOHS($bus);

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
        new ShoppingListItemCommandOHS($bus)->addItem('user-id', 'pain');
    }

    public function testAHandlerThatReturnsNothingIsABug(): void
    {
        // Given
        $bus = new MockCommandBus();

        // Then
        $this->expectException(LogicException::class);

        // When
        new ShoppingListItemCommandOHS($bus)->addItem('user-id', 'pain');
    }

    public function testItDispatchesTheRemovalAndReturnsTheResultOfTheHandler(): void
    {
        // Given
        $result = new RemovedItem(found: true, remaining: 2.0, removed: 1.0);
        $bus = new MockCommandBus()->willReturn(RemoveSpokenIngredientCommand::class, $result);

        // When
        $removed = new ShoppingListItemCommandOHS($bus)->removeItem('user-id', 'pain', 3, 'kilo');

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
        new ShoppingListItemCommandOHS($bus)->removeItem('user-id', 'pain');
    }
}
