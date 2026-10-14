<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class EnsureActiveShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testEnsuresAnActiveListForTheCurrentUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(EnsureActiveShoppingListCommand::class, $command);
        $this->assertSame($owner->getId(), $command->ownerId);
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
