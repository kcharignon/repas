<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class MergeIngredientsApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheMergeWithTheUserOfTheTokenAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/merge', server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['into' => 'tomate']));

        // Then : l'utilisateur vient du jeton, jamais du corps ; l'ingrédient de l'URL est l'absorbé, `into` le gardé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new MergeIngredientsCommand($user->getId(), 'tomates', 'tomate')], $commandBus->dispatched());
    }

    public function testTheUserOfTheBodyIsIgnored(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/merge', server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['into' => 'tomate', 'userId' => 'quelquun-d-autre', 'user' => 'quelquun-d-autre']));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new MergeIngredientsCommand($user->getId(), 'tomates', 'tomate')], $commandBus->dispatched());
    }

    public function testAnAdministratorHasNoMoreRightsThanAnyoneElse(): void
    {
        // Given : le cas d'usage reçoit l'identifiant de l'administrateur, sans rôle ni passe-droit
        $commandBus = $this->mockCommandBus();
        $admin = $this->savedUser(true);

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/merge', server: $this->bearerFor($admin) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['into' => 'tomate']));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new MergeIngredientsCommand($admin->getId(), 'tomates', 'tomate')], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{IngredientException, string, int}>
     */
    public static function domainFailures(): iterable
    {
        yield 'ingrédient inconnu ou à un autre utilisateur' => [IngredientException::notFound('tomates'), "Ingredient 'tomates' not found", 404];
        yield 'les deux ingrédients sont le même' => [IngredientException::cannotMergeIntoItself(), 'CANNOT_MERGE_INTO_ITSELF', 422];
        yield 'une unité qui manque' => [IngredientException::mergeUnitMissing(), 'MERGE_UNIT_MISSING', 422];
        yield 'une liste envoyée au drive' => [IngredientException::inSentList(), 'INGREDIENT_IN_SENT_LIST', 409];
        yield 'la ligne d\'un autre utilisateur' => [IngredientException::usedByAnotherUser(), 'INGREDIENT_USED_BY_ANOTHER_USER', 409];
        yield 'une écriture concurrente' => [IngredientException::mergeConflict(), 'INGREDIENT_MERGE_CONFLICT', 409];
    }

    #[DataProvider('domainFailures')]
    public function testADomainRefusalIsAnsweredWithItsStatusAndItsCode(IngredientException $failure, string $message, int $status): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $commandBus->willThrow(MergeIngredientsCommand::class, $failure);

        // When
        $this->client->request('POST', '/api/v1/ingredients/tomates/merge', server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['into' => 'tomate']));

        // Then : le message est le code en majuscules (ADR 0007), jamais une phrase à afficher
        $this->assertSame($status, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => $message]], $this->jsonBody());
    }

    public function testABodyWithoutTargetIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        foreach ([[], ['into' => ''], ['into' => str_repeat('a', 256)], ['into' => 12]] as $body) {
            // When
            $this->client->request('POST', '/api/v1/ingredients/tomates/merge', server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));

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
        $this->client->request('POST', '/api/v1/ingredients/tomates/merge', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['into' => 'tomate']));

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testNoGetOnTheRouteOfTheMerge(): void
    {
        // Given : une mutation n'est jamais acceptée en GET
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('GET', '/api/v1/ingredients/tomates/merge', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(405, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
