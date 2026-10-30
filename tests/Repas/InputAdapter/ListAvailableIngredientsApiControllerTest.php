<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\ListAvailableIngredientsQuery;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListAvailableIngredientsApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItemsForTheAuthenticatedUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListAvailableIngredientsQuery::class, [
            new IngredientOptionDTO('farine', 'farine', 'epicerie', 'épicerie'),
            new IngredientOptionDTO('oeuf', 'œuf', 'surgele', 'surgelé'),
        ]);

        // When
        $this->client->request('GET', '/api/v1/ingredients', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListAvailableIngredientsQuery($user->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                ['slug' => 'farine', 'name' => 'farine', 'departmentSlug' => 'epicerie', 'departmentName' => 'épicerie'],
                ['slug' => 'oeuf', 'name' => 'œuf', 'departmentSlug' => 'surgele', 'departmentName' => 'surgelé'],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testTheSearchIsTransmittedToTheQuery(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListAvailableIngredientsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/ingredients?q=cre', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListAvailableIngredientsQuery($user->getId(), 'cre'), $this->queryBus->getLastAskedQuery());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testASearchThatIsTooLongIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients?q=' . str_repeat('a', 101), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testListWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
