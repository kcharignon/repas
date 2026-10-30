<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\ChangeRecipeFavorite\ChangeRecipeFavoriteCommand;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class ChangeRecipeFavoriteApiControllerTest extends QueryBusWebTestCase
{
    public function testPutsTheRecipeInFavoritesForTheAuthenticatedUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put($recipe->getId(), $author, ['favorite' => true]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ChangeRecipeFavoriteCommand($recipe->getId(), $author->getId(), true)], $commandBus->dispatched());
    }

    public function testTakesTheRecipeOutOfFavoritesAndForwardsTheExpectedVersion(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put($recipe->getId(), $author, ['favorite' => false], ['HTTP_IF_MATCH' => '"4"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ChangeRecipeFavoriteCommand($recipe->getId(), $author->getId(), false, 4)], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnythingButABooleanAndDispatchesNothing(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put($recipe->getId(), $author, $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans la clé' => [[]];
        yield 'null' => [['favorite' => null]];
        yield 'une chaîne' => [['favorite' => 'oui']];
        yield 'un entier' => [['favorite' => 1]];
        yield 'une liste' => [['favorite' => [true]]];
    }

    public function testRefusesAUserWhoIsNotTheAuthorBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $recipe = $this->savedRecipe($this->savedUser());

        // When
        $this->put($recipe->getId(), $this->savedUser(), ['favorite' => true]);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/recipes/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34/favorite', content: '{"favorite":true}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedRecipe(User $author): Recipe
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function put(string $recipeId, User $user, array $body, array $headers = []): void
    {
        $this->client->request('PUT', '/api/v1/recipes/' . $recipeId . '/favorite', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json'] + $headers);
    }
}
