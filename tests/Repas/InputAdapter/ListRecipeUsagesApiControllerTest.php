<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipeUsages\ListRecipeUsagesQuery;
use Repas\Repas\Application\Query\ListRecipeUsages\RecipeUsagesDTO;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListRecipeUsagesApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheRecipesThatUseTheRecipeOfTheCurrentUser(): void
    {
        // Given
        $author = $this->savedUser();
        $dough = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($dough);
        $this->queryBus->willReturn(ListRecipeUsagesQuery::class, new RecipeUsagesDTO([new RecipeSummaryDTO('pie', 'Tarte', false), new RecipeSummaryDTO('quiche', 'Quiche', true)], 2));

        // When
        $this->client->request('GET', '/api/v1/recipes/' . $dough->getId() . '/used-in', server: $this->bearerFor($author));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListRecipeUsagesQuery::class, $query);
        $this->assertSame([$dough->getId(), $author->getId()], [$query->recipeId, $query->userId]);
        $this->assertSame([
            'member' => [['id' => 'pie', 'name' => 'Tarte', 'secret' => false], ['id' => 'quiche', 'name' => 'Quiche', 'secret' => true]],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testRefusesTheRecipeOfAnotherUserHidesASecretOneAndNeverAsks(): void
    {
        // Given
        $author = $this->savedUser();
        $ordinary = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $secret = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->asSecret()->build();
        $this->persister->saveRecipe($ordinary);
        $this->persister->saveRecipe($secret);
        $other = $this->savedUser();

        // When & Then : `403` pour une recette ordinaire d'un autre, `404` pour sa recette secrète ou un identifiant inconnu
        $this->client->request('GET', '/api/v1/recipes/' . $ordinary->getId() . '/used-in', server: $this->bearerFor($other));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/v1/recipes/' . $secret->getId() . '/used-in', server: $this->bearerFor($other));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/api/v1/recipes/00000000-0000-4000-8000-000000000001/used-in', server: $this->bearerFor($other));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/00000000-0000-4000-8000-000000000001/used-in');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
