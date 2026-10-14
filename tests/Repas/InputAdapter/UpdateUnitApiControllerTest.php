<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateUnitApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorReplacesIt(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => 'Pincée', 'symbol' => 'pin']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUnitCommand('pincee', 'Pincée', 'pin')], $commandBus->dispatched());
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['name' => 'Pincée', 'symbol' => 'pin']);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnInvalidBody(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => ''] + ['name' => 'Pincée', 'symbol' => 'pin']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/units/pincee', content: json_encode(['name' => 'Pincée', 'symbol' => 'pin']), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/units/pincee', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
