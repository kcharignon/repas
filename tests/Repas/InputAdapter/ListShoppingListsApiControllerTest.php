<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQuery;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListShoppingListsApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItemsOfTheAuthenticatedUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListShoppingListsQuery::class, [
            new ShoppingListDTOBuilder()->withId('list-1')->withDisplayName('Courses du week-end')->withStatus(ShoppingListStatus::ACTIVE)->withCreatedAt('2026-10-12T10:00:00+02:00')->build(),
            new ShoppingListDTOBuilder()->withId('list-2')->withDisplayName('20 janvier 2025')->withStatus(ShoppingListStatus::COMPLETED)->withCreatedAt('2025-01-20T10:56:23+01:00')->build(),
        ]);

        // When
        $this->client->request('GET', '/api/v1/shopping-lists', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListShoppingListsQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $this->assertSame([
            'member' => [
                [
                    'id' => 'list-1',
                    'displayName' => 'Courses du week-end',
                    'status' => 'ACTIVE',
                    'isActive' => true,
                    'isPaused' => false,
                    'isCompleted' => false,
                    'createdAt' => '2026-10-12T10:00:00+02:00',
                ],
                [
                    'id' => 'list-2',
                    'displayName' => '20 janvier 2025',
                    'status' => 'COMPLETED',
                    'isActive' => false,
                    'isPaused' => false,
                    'isCompleted' => true,
                    'createdAt' => '2025-01-20T10:56:23+01:00',
                ],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testListReturnsAnEmptyCollection(): void
    {
        // Given
        $this->queryBus->willReturn(ListShoppingListsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/shopping-lists', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testListWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-lists');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
