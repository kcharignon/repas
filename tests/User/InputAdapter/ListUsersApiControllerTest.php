<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\Query\ListUsers\ListUsersQuery;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;

class ListUsersApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorGetsMembersAndTotalItems(): void
    {
        // Given
        $this->queryBus->willReturn(ListUsersQuery::class, [
            new UserSummaryDTO('user-1', 'admin@example.com', 5, 3, 2, 4, '2026-10-13T08:56:40+02:00', false, true, 'ACTIVE'),
        ]);

        // When
        $this->client->request('GET', '/api/v1/users', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListUsersQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [[
                'id' => 'user-1',
                'email' => 'admin@example.com',
                'defaultServing' => 5,
                'recipeCount' => 3,
                'ingredientCount' => 2,
                'shoppingListCount' => 4,
                'createdAt' => '2026-10-13T08:56:40+02:00',
                'isDisabled' => false,
                'isAdmin' => true,
                'status' => 'ACTIVE',
            ]],
            'totalItems' => 1,
        ], $this->jsonBody());
    }

    public function testAUserWithoutTheAdminRoleIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/users', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/users');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
