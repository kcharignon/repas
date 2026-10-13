<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\Builder\DTO\CurrentUserDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQuery;

class GetCurrentUserApiControllerTest extends QueryBusWebTestCase
{
    public function testMeAsksTheQueryForTheAuthenticatedUserAndReturnsTheDTO(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetCurrentUserQuery::class, new CurrentUserDTOBuilder()
            ->withId($user->getId())->withEmail('alexiane@example.com')->withDefaultServing(3)->build());

        // When
        $this->client->request('GET', '/api/v1/me', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetCurrentUserQuery::class, $query);
        $this->assertSame($user->getId(), $query->userId);
        $this->assertSame([
            'id' => $user->getId(),
            'email' => 'alexiane@example.com',
            'roles' => ['ROLE_USER'],
            'isAdmin' => false,
            'defaultServing' => 3,
        ], $this->jsonBody());
    }

    public function testMeFlagsAnAdministrator(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $this->queryBus->willReturn(GetCurrentUserQuery::class, new CurrentUserDTOBuilder()->withId($admin->getId())->isAdmin()->build());

        // When
        $this->client->request('GET', '/api/v1/me', server: $this->bearerFor($admin));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->jsonBody()['isAdmin']);
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $this->jsonBody()['roles']);
    }

    public function testMeWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/me');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
