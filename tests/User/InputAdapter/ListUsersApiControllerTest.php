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
            new UserSummaryDTO('user-1', 'admin@example.com', 5, 3, 2, 4, '2026-10-13T08:56:40+02:00', '2026-10-20T08:30:12+00:00', false, true, 'ACTIVE', '0.1.8', true),
            new UserSummaryDTO('user-2', 'navigateur@example.com', 4, 0, 0, 0, '', null, false, false, 'ACTIVE', null, false),
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
                'lastLoginAt' => '2026-10-20T08:30:12+00:00',
                'isDisabled' => false,
                'isAdmin' => true,
                'status' => 'ACTIVE',
                'appVersion' => '0.1.8',
                'appOutdated' => true,
            ], [
                'id' => 'user-2',
                'email' => 'navigateur@example.com',
                'defaultServing' => 4,
                'recipeCount' => 0,
                'ingredientCount' => 0,
                'shoppingListCount' => 0,
                'createdAt' => '',
                'lastLoginAt' => null,
                'isDisabled' => false,
                'isAdmin' => false,
                'status' => 'ACTIVE',
                'appVersion' => null,
                'appOutdated' => false,
            ]],
            'totalItems' => 2,
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
