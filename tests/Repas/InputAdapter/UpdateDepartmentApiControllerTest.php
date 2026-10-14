<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateDepartmentApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorReplacesIt(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => 'Surgelés', 'image' => 'images/frozen.svg']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateDepartmentCommand('surgeles', 'Surgelés', 'images/frozen.svg')], $commandBus->dispatched());
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['name' => 'Surgelés', 'image' => 'images/frozen.svg']);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnInvalidBody(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => ''] + ['name' => 'Surgelés', 'image' => 'images/frozen.svg']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/departments/surgeles', content: json_encode(['name' => 'Surgelés', 'image' => 'images/frozen.svg']), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/departments/surgeles', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
