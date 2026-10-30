<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListFavoriteRecipes\ListFavoriteRecipesQuery;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListFavoriteRecipesApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheFavoritesOfTheAuthenticatedUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListFavoriteRecipesQuery::class, [new RecipeSummaryDTO('recipe-1', 'omelette', false), new RecipeSummaryDTO('recipe-2', 'pâtes', true)]);

        // When
        $this->client->request('GET', '/api/v1/recipes/favorites', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListFavoriteRecipesQuery::class, $query);
        $this->assertSame($user->getId(), $query->authorId);
        $this->assertSame([
            'member' => [['id' => 'recipe-1', 'name' => 'omelette', 'secret' => false], ['id' => 'recipe-2', 'name' => 'pâtes', 'secret' => true]],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testWithoutFavoriteTheListIsEmpty(): void
    {
        // Given
        $this->queryBus->willReturn(ListFavoriteRecipesQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/recipes/favorites', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/favorites');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
