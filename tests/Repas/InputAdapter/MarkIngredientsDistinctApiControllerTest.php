<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\MarkIngredientsDistinct\MarkIngredientsDistinctCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class MarkIngredientsDistinctApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandWithTheUserOfTheTokenAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/distinct-from', server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['other' => 'tomate']));

        // Then : l'utilisateur vient du jeton, jamais du corps
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new MarkIngredientsDistinctCommand($user->getId(), 'tomates', 'tomate')], $commandBus->dispatched());
    }

    public function testTheUserOfTheBodyIsIgnored(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/distinct-from', server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['other' => 'tomate', 'userId' => 'quelquun-d-autre']));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new MarkIngredientsDistinctCommand($user->getId(), 'tomates', 'tomate')], $commandBus->dispatched());
    }

    public function testAnAdministratorHasNoMoreRightsThanAnyoneElse(): void
    {
        // Given : le cas d'usage reçoit l'identifiant de l'administrateur, sans rôle ni passe-droit
        $commandBus = $this->mockCommandBus();
        $admin = $this->savedUser(true);

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/distinct-from', server: $this->bearerFor($admin) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['other' => 'tomate']));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new MarkIngredientsDistinctCommand($admin->getId(), 'tomates', 'tomate')], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{IngredientException, string, int}>
     */
    public static function domainFailures(): iterable
    {
        yield 'ingrédient inconnu, commun ou à un autre utilisateur' => [IngredientException::notFound('tomates'), "Ingredient 'tomates' not found", 404];
        yield 'les deux ingrédients sont le même' => [IngredientException::cannotBeDistinctFromItself(), 'CANNOT_BE_DISTINCT_FROM_ITSELF', 422];
    }

    #[DataProvider('domainFailures')]
    public function testADomainRefusalIsAnsweredWithItsStatusAndItsCode(IngredientException $failure, string $message, int $status): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $commandBus->willThrow(MarkIngredientsDistinctCommand::class, $failure);

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/distinct-from', server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['other' => 'tomate']));

        // Then : le message est le code en majuscules (ADR 0007), jamais une phrase à afficher
        $this->assertSame($status, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $message]], $this->jsonBody());
    }

    public function testABodyWithoutTheOtherIngredientIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        foreach ([[], ['other' => ''], ['other' => str_repeat('a', 256)], ['other' => 12]] as $body) {
            // When
            $this->client->request('POST', '/api/v1/ingredients/tomates/distinct-from', server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));

            // Then
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($body));
            $this->assertArrayHasKey('error', $this->jsonBody());
        }
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/distinct-from', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['other' => 'tomate']));

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testNoGetOnTheRouteOfTheMark(): void
    {
        // Given : une mutation n'est jamais acceptée en GET
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/distinct-from', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
