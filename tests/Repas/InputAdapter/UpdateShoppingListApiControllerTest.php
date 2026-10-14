<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class UpdateShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/' . $shoppingList->getId(), content: json_encode(['name' => 'Courses du week-end'], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateShoppingListCommand($shoppingList->getId(), 'Courses du week-end')], $commandBus->dispatched());
    }

    public function testRefusesAResourceOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/' . $shoppingList->getId(), content: json_encode(['name' => 'Courses du week-end'], JSON_THROW_ON_ERROR), server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownResource(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/unknown-list', content: json_encode(['name' => 'Courses du week-end'], JSON_THROW_ON_ERROR), server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesANameThatIsTooLong(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/' . $shoppingList->getId(), content: json_encode(['name' => str_repeat('a', 256)], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesANameOfTheWrongType(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/' . $shoppingList->getId(), content: json_encode(['name' => ['liste']], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAbsentNameRestoresTheDefaultName(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/' . $shoppingList->getId(), content: json_encode([], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateShoppingListCommand($shoppingList->getId(), null)], $commandBus->dispatched());
    }

    public function testANullNameRestoresTheDefaultName(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $shoppingList = $this->savedShoppingList($owner);

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/' . $shoppingList->getId(), content: json_encode(['name' => null], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateShoppingListCommand($shoppingList->getId(), null)], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PATCH', '/api/v1/shopping-lists/unknown-list', content: json_encode(['name' => 'Courses du week-end'], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
