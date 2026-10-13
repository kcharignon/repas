<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\DTO\RecipeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetRecipeApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheRecipeWithItsRows(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willReturn(GetRecipeQuery::class, new RecipeDTOBuilder()
            ->withId($id)->withName('pâtes carbonara')->withServing(4)->withType('plat', 'plat')
            ->withRow(new RecipeRowDTO('pate', 'pâtes', 'images/pate.png', 500.0, 'gramme', 'g'))
            ->build());

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetRecipeQuery::class, $query);
        $this->assertSame($id, $query->recipeId);
        $this->assertSame([
            'id' => $id,
            'name' => 'pâtes carbonara',
            'serving' => 4,
            'typeSlug' => 'plat',
            'typeName' => 'plat',
            'rows' => [[
                'ingredientSlug' => 'pate',
                'ingredientName' => 'pâtes',
                'ingredientImage' => 'images/pate.png',
                'quantity' => 500,
                'unitSlug' => 'gramme',
                'unitSymbol' => 'g',
            ]],
        ], $this->jsonBody());
    }

    public function testReturns404ForAnUnknownRecipe(): void
    {
        // Given
        $id = UuidGenerator::new();
        $this->queryBus->willThrow(GetRecipeQuery::class, RecipeException::notFound($id));

        // When
        $this->client->request('GET', "/api/v1/recipes/$id", server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/' . UuidGenerator::new());

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
