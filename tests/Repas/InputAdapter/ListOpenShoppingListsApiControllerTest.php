<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListOpenShoppingLists\ListOpenShoppingListsQuery;
use Repas\Repas\Application\Query\ListOpenShoppingLists\OpenShoppingListDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListOpenShoppingListsApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsTheOpenListsOfTheAuthenticatedUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListOpenShoppingListsQuery::class, [
            new OpenShoppingListDTO('list-1', '12 octobre 2026', null, 12, 5, 3),
            new OpenShoppingListDTO('list-2', 'Boucherie', 'type-1', 0, 0, 1),
        ]);

        // When
        $this->client->request('GET', '/api/v1/shopping-lists/open', server: $this->bearerFor($user));

        // Then : « open » n'est pas lu comme l'identifiant d'une liste
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListOpenShoppingListsQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $this->assertSame([
            'member' => [
                ['id' => 'list-1', 'name' => '12 octobre 2026', 'typeId' => null, 'rowCount' => 12, 'uncheckedCount' => 5, 'version' => 3],
                ['id' => 'list-2', 'name' => 'Boucherie', 'typeId' => 'type-1', 'rowCount' => 0, 'uncheckedCount' => 0, 'version' => 1],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testListReturnsAnEmptyCollection(): void
    {
        // Given
        $this->queryBus->willReturn(ListOpenShoppingListsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/shopping-lists/open', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testListWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-lists/open');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
