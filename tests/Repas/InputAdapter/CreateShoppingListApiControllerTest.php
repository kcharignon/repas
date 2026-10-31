<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCreatesATypedListWhenTheBodyCarriesATypeId(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists', server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json'], content: '{"typeId":"type-1"}');

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(CreateShoppingListCommand::class, $command);
        $this->assertSame('type-1', $command->listTypeId);
    }

    #[DataProvider('mainListBodies')]
    public function testAnAbsentOrNullTypeIdIsTheMainList(?string $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists', server: $this->bearerFor($owner) + ($body === null ? [] : ['CONTENT_TYPE' => 'application/json']), content: $body);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertNull($commandBus->dispatched()[0]->listTypeId);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function mainListBodies(): iterable
    {
        yield 'type nul' => ['{"typeId":null}'];
        yield 'objet vide' => ['{}'];
        yield 'sans corps' => [null];
    }

    public function testRefusesATypeIdThatIsNotAnIdentifierAndDispatchesNothing(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When / Then
        foreach (['{"typeId":12}', '{"typeId":""}', '{"typeId":["a"]}', '{"typeId":true}'] as $body) {
            $this->client->request('POST', '/api/v1/shopping-lists', server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json'], content: $body);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $body);
        }
        $this->assertSame([], $commandBus->dispatched());
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
