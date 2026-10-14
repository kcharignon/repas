<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\RemoveShoppingList\RemoveShoppingListCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('DELETE', '/api/v1/shopping-lists/' . $shoppingList->getId(), server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RemoveShoppingListCommand($shoppingList->getId())], $commandBus->dispatched());
    }

    public function testRefusesAResourceOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('DELETE', '/api/v1/shopping-lists/' . $shoppingList->getId(), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownResource(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/shopping-lists/unknown-list', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/shopping-lists/unknown-list');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
