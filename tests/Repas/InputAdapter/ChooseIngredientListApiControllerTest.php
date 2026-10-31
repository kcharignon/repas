<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\ChooseIngredientList\ChooseIngredientListCommand;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class ChooseIngredientListApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandForTheOwnerOfTheIngredient(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, ['destination' => 'main']);

        // Then : le produit vient du chemin, l'utilisateur du jeton
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new ChooseIngredientListCommand($ingredient->getSlug(), 'main', $owner->getId())], $commandBus->dispatched());
    }

    #[DataProvider('destinations')]
    public function testPassesTheDestinationAsReceived(string $destination): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, ['destination' => $destination]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ChooseIngredientListCommand($ingredient->getSlug(), $destination, $owner->getId())], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function destinations(): iterable
    {
        yield 'comme son rayon' => ['default'];
        yield 'la liste principale' => ['main'];
        yield 'un type' => ['3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34'];
    }

    public function testRefusesTheIngredientOfAnotherUserEvenToAnAdministratorBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $ingredient = $this->savedIngredient($this->savedUser());

        // When
        $this->put($ingredient->getSlug(), $this->savedUser(admin: true), ['destination' => 'main']);

        // Then : pas de passe-droit
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    #[DataProvider('handlerRefusals')]
    public function testTheRefusalsOfTheHandlerKeepTheirStatusAndCode(\Throwable $exception, int $status, string $message): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willThrow(ChooseIngredientListCommand::class, $exception);
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, ['destination' => '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34']);

        // Then
        $this->assertSame([$status, ['error' => ['message' => $message]]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertCount(1, $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function handlerRefusals(): iterable
    {
        yield 'type inconnu ou d\'un autre' => [ShoppingListTypeException::notFound(), 404, 'LIST_TYPE_NOT_FOUND'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBodyAndDispatchesNothing(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans corps' => [[]];
        yield 'destination vide' => [['destination' => '']];
        yield 'destination nulle' => [['destination' => null]];
        yield 'destination qui est un nombre' => [['destination' => 12]];
        yield 'destination qui est une liste' => [['destination' => ['main']]];
        yield 'destination de plus de 36 caractères' => [['destination' => str_repeat('a', 37)]];
    }

    public function testAnUnknownIngredientIsNotFoundBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('inconnu', $this->savedUser(), ['destination' => 'main']);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/ingredients/beurre/list-destination', content: '{"destination":"main"}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedIngredient(User $owner): Ingredient
    {
        $ingredient = new IngredientBuilder()->isButter()->withCreator($owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $slug, User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/ingredients/' . $slug . '/list-destination', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
