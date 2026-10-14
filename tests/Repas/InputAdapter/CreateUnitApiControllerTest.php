<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateUnit\CreateUnitCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class CreateUnitApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorCreatesItAndGetsTheLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateUnitCommand::class, 'pincee');

        // When
        $this->post($this->savedUser(admin: true), ['name' => 'Pincée', 'symbol' => 'pin']);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateUnitCommand('Pincée', 'pin')], $commandBus->dispatched());
        $this->assertSame('/api/v1/units/pincee', $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), ['name' => 'Pincée', 'symbol' => 'pin']);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(admin: true), $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        $valid = ['name' => 'Pincée', 'symbol' => 'pin'];

        yield 'sans nom' => [['name' => ''] + $valid];
        yield 'nom trop long' => [['name' => str_repeat('a', 256)] + $valid];
        yield 'sans symbole' => [['symbol' => ''] + $valid];
        yield 'symbole trop long' => [['symbol' => str_repeat('a', 51)] + $valid];
        yield 'nom de mauvais type' => [['name' => ['x']] + $valid];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/units', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/units', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
