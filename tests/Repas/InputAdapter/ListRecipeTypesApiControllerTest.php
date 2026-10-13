<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListRecipeTypesApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItems(): void
    {
        // Given
        $this->queryBus->willReturn(ListRecipeTypesQuery::class, [
            new RecipeTypeDTOBuilder()->withSlug('entree')->withName('entrée')->withImage('images/entree.svg')->withOrder(1)->build(),
            new RecipeTypeDTOBuilder()->withSlug('plat')->withName('plat')->withImage('images/plat.svg')->withOrder(2)->build(),
        ]);

        // When
        $this->client->request('GET', '/api/v1/recipe-types', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListRecipeTypesQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                ['slug' => 'entree', 'name' => 'entrée', 'image' => 'images/entree.svg', 'order' => 1],
                ['slug' => 'plat', 'name' => 'plat', 'image' => 'images/plat.svg', 'order' => 2],
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
