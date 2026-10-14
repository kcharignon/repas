<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateDepartment\CreateDepartmentCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class CreateDepartmentApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorCreatesItAndGetsTheLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateDepartmentCommand::class, 'surgeles');

        // When
        $this->post($this->savedUser(admin: true), ['name' => 'Surgelés', 'image' => 'images/frozen.svg']);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateDepartmentCommand('Surgelés', 'images/frozen.svg')], $commandBus->dispatched());
        $this->assertSame('/api/v1/departments/surgeles', $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), ['name' => 'Surgelés', 'image' => 'images/frozen.svg']);

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
        $valid = ['name' => 'Surgelés', 'image' => 'images/frozen.svg'];

        yield 'sans nom' => [['name' => ''] + $valid];
        yield 'sans image' => [['image' => ''] + $valid];
        yield 'image http non sécurisée' => [['image' => 'http://exemple.org/a.png'] + $valid];
        yield 'image javascript' => [['image' => 'javascript:alert(1)'] + $valid];
        yield 'chemin hors images' => [['image' => '../secret.png'] + $valid];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/departments', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/departments', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
