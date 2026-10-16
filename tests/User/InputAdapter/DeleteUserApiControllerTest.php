<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\DeleteUser\DeleteUserCommand;

class DeleteUserApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorDeletesAnAccount(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/users/target-id', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new DeleteUserCommand('target-id')], $commandBus->dispatched());
    }

    public function testAUserWithoutTheAdminRoleIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When : même son propre compte
        $this->client->request('DELETE', '/api/v1/users/' . $user->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/users/target-id');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
