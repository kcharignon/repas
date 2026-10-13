<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetRecipeType\GetRecipeTypeQuery;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetRecipeTypeApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheItem(): void
    {
        // Given
        $this->queryBus->willReturn(GetRecipeTypeQuery::class, new RecipeTypeDTOBuilder()->withSlug('zzz')->withName('zzz brunch')->withImage('images/zzz.svg')->withOrder(7)->build());

        // When
        $this->client->request('GET', '/api/v1/recipe-types/zzz', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeTypeQuery::class, $query);
        $this->assertSame('zzz', $query->slug);
        $this->assertSame(['slug' => 'zzz', 'name' => 'zzz brunch', 'image' => 'images/zzz.svg', 'order' => 7], $this->jsonBody());
    }

    public function testReturns404ForAnUnknownItem(): void
    {
        // Given
        $this->queryBus->willThrow(GetRecipeTypeQuery::class, RecipeException::typeNotFound('zzz'));

        // When
        $this->client->request('GET', '/api/v1/recipe-types/zzz', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipe-types/zzz');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
