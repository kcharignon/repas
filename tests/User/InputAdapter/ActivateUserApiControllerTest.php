<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\ActivateUser\ActivateUserCommand;

class ActivateUserApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorActivatesAnAccount(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/users/target-id/activate', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ActivateUserCommand('target-id')], $commandBus->dispatched());
    }

    public function testAUserWithoutTheAdminRoleIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/users/target-id/activate', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/users/target-id/activate');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
