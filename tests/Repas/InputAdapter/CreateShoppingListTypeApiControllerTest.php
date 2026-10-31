<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateShoppingListType\CreateShoppingListTypeCommand;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class CreateShoppingListTypeApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsTheLocationOfTheNewType(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When : un propriétaire dans le corps est ignoré, c'est l'utilisateur du jeton
        $this->post($user, ['name' => 'Boucherie', 'ownerId' => 'someone-else']);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $dispatched = $commandBus->dispatched();
        $this->assertCount(1, $dispatched);
        $command = $dispatched[0];
        $this->assertInstanceOf(CreateShoppingListTypeCommand::class, $command);
        $this->assertSame(['Boucherie', $user->getId()], [$command->name, $command->userId]);
        $this->assertSame('/api/v1/shopping-list-types/' . $command->id, $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheLimitIsAnUnprocessableEntityWithAStableCode(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willThrow(CreateShoppingListTypeCommand::class, ShoppingListTypeException::limitReached());

        // When
        $this->post($this->savedUser(), ['name' => 'Onzième']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'LIST_TYPE_LIMIT_REACHED']], $this->jsonBody());
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testANameAlreadyUsedIsAConflictWithAStableCode(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(CreateShoppingListTypeCommand::class, ShoppingListTypeException::nameAlreadyUsed());

        // When
        $this->post($this->savedUser(), ['name' => 'Boucherie']);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'LIST_TYPE_NAME_ALREADY_USED']], $this->jsonBody());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBodyAndDispatchesNothing(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans nom' => [[]];
        yield 'nom vide' => [['name' => '']];
        yield 'nom nul' => [['name' => null]];
        yield 'nom de 41 caractères' => [['name' => str_repeat('a', 41)]];
        yield 'nom qui est un nombre' => [['name' => 12]];
        yield 'nom qui est une liste' => [['name' => ['Boucherie']]];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-list-types', content: '{"name":"Boucherie"}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(\Repas\User\Domain\Model\User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/shopping-list-types', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
