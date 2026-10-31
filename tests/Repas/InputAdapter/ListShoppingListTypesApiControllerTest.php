<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListShoppingListTypes\ListShoppingListTypesQuery;
use Repas\Repas\Application\Query\ListShoppingListTypes\ShoppingListTypeSummaryDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListShoppingListTypesApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheTypesOfTheAuthenticatedUserWithTheirKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListShoppingListTypesQuery::class, [
            new ShoppingListTypeSummaryDTO('type-1', 'Boucherie', ['viandes'], 2),
            new ShoppingListTypeSummaryDTO('type-2', 'Marché', [], 0),
        ]);

        // When
        $this->client->request('GET', '/api/v1/shopping-list-types', server: $this->bearerFor($user));

        // Then : l'utilisateur vient du jeton
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListShoppingListTypesQuery::class, $query);
        $this->assertSame($user->getId(), $query->userId);
        $this->assertSame([
            'member' => [
                ['id' => 'type-1', 'name' => 'Boucherie', 'departmentSlugs' => ['viandes'], 'ingredientCount' => 2],
                ['id' => 'type-2', 'name' => 'Marché', 'departmentSlugs' => [], 'ingredientCount' => 0],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testWithoutTypeTheListIsEmpty(): void
    {
        // Given
        $this->queryBus->willReturn(ListShoppingListTypesQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/shopping-list-types', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-list-types');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
