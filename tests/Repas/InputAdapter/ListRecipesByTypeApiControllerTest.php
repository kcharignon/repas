<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListRecipesByType\ListRecipesByTypeQuery;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Tests\Helper\Builder\DTO\RecipeTypeDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListRecipesByTypeApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheTypeAndTheRecipesOfTheAuthenticatedUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListRecipesByTypeQuery::class, new RecipeTypeRecipesDTO(
            new RecipeTypeDTOBuilder()->withSlug('plat')->withName('plat')->withImage('images/plat.svg')->withOrder(3)->build(),
            [new RecipeSummaryDTO('recipe-1', 'omelette'), new RecipeSummaryDTO('recipe-2', 'pâtes')],
        ));

        // When
        $this->client->request('GET', '/api/v1/recipe-types/plat/recipes', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListRecipesByTypeQuery::class, $query);
        $this->assertSame([$user->getId(), 'plat'], [$query->authorId, $query->typeSlug]);
        $this->assertSame([
            'recipeType' => ['slug' => 'plat', 'name' => 'plat', 'image' => 'images/plat.svg', 'order' => 3],
            'member' => [['id' => 'recipe-1', 'name' => 'omelette'], ['id' => 'recipe-2', 'name' => 'pâtes']],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testReturns404ForAnUnknownType(): void
    {
        // Given
        $this->queryBus->willThrow(ListRecipesByTypeQuery::class, RecipeException::typeNotFound('inconnu'));

        // When
        $this->client->request('GET', '/api/v1/recipe-types/inconnu/recipes', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipe-types/plat/recipes');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
