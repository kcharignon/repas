<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\DisableUser\DisableUserCommand;

class DisableUserApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorDisablesAnAccountAndIsTheActingUser(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/users/target-id/disable', server: $this->bearerFor($admin));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new DisableUserCommand('target-id', $admin->getId())], $commandBus->dispatched());
    }

    public function testAUserWithoutTheAdminRoleIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/users/target-id/disable', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/users/target-id/disable');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
