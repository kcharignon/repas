<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class CreateShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testCreatesAListForTheCurrentUserAndReturnsItsLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(CreateShoppingListCommand::class, $command);
        $this->assertSame($owner->getId(), $command->ownerId);
        $this->assertSame('/api/v1/shopping-lists/' . $command->shoppingListId, $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
