<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\Builder\DTO\CurrentUserDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQuery;

class GetUserApiControllerTest extends QueryBusWebTestCase
{
    public function testAUserReadsHimself(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetCurrentUserQuery::class, new CurrentUserDTOBuilder()->withId($user->getId())->withEmail('alexiane@example.com')->withDefaultServing(3)->build());

        // When
        $this->client->request('GET', '/api/v1/users/' . $user->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetCurrentUserQuery($user->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame(['id', 'email', 'roles', 'isAdmin', 'defaultServing'], array_keys($this->jsonBody()));
    }

    public function testAnAdministratorReadsAnotherUser(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $other = $this->savedUser();
        $this->queryBus->willReturn(GetCurrentUserQuery::class, new CurrentUserDTOBuilder()->withId($other->getId())->build());

        // When
        $this->client->request('GET', '/api/v1/users/' . $other->getId(), server: $this->bearerFor($admin));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new GetCurrentUserQuery($other->getId()), $this->queryBus->getLastAskedQuery());
    }

    public function testRefusesAnotherUser(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();

        // When
        $this->client->request('GET', '/api/v1/users/' . $other->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // When
        $this->client->request('GET', '/api/v1/users/any-id');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
