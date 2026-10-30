<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeCountDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListRecipeTypesApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItems(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListRecipeTypesQuery::class, [
            new RecipeTypeCountDTO('entree', 'entrée', 'images/entree.svg', 1, 3),
            new RecipeTypeCountDTO('plat', 'plat', 'images/plat.svg', 2, 0),
        ]);

        // When
        $this->client->request('GET', '/api/v1/recipe-types', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListRecipeTypesQuery::class, $query);
        $this->assertSame($user->getId(), $query->userId);
        $this->assertSame([
            'member' => [
                ['slug' => 'entree', 'name' => 'entrée', 'image' => 'images/entree.svg', 'order' => 1, 'recipeCount' => 3],
                ['slug' => 'plat', 'name' => 'plat', 'image' => 'images/plat.svg', 'order' => 2, 'recipeCount' => 0],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testListReturnsAnEmptyCollection(): void
    {
        // Given
        $this->queryBus->willReturn(ListRecipeTypesQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/recipe-types', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testListWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipe-types');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
