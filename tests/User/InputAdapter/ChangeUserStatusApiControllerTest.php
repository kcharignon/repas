<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\ChangeUserStatus\ChangeUserStatusCommand;
use Repas\User\Domain\Model\UserStatus;

class ChangeUserStatusApiControllerTest extends QueryBusWebTestCase
{
    #[DataProvider('statuses')]
    public function testAnAdministratorSetsTheStatusOfAnAccount(string $status, UserStatus $expected): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('/api/v1/users/target-id/status', ['status' => $status], $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new ChangeUserStatusCommand('target-id', $expected)], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{string, UserStatus}>
     */
    public static function statuses(): iterable
    {
        yield 'en attente' => ['PENDING', UserStatus::PENDING];
        yield 'actif' => ['ACTIVE', UserStatus::ACTIVE];
        yield 'désactivé' => ['DISABLED', UserStatus::DISABLED];
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
        $this->put('/api/v1/users/target-id/status', $body, $this->bearerFor($this->savedUser(admin: true)));

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
        yield 'sans statut' => [[]];
        yield 'statut vide' => [['status' => '']];
        yield 'statut inconnu' => [['status' => 'BANNED']];
        yield 'statut en minuscules' => [['status' => 'active']];
        yield 'statut qui n\'est pas du texte' => [['status' => 3]];
    }

    public function testAUserWithoutTheAdminRoleIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When : même pour son propre compte
        $this->put('/api/v1/users/' . $user->getId() . '/status', ['status' => 'ACTIVE'], $this->bearerFor($user));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('/api/v1/users/target-id/status', ['status' => 'ACTIVE']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $server
     */
    private function put(string $uri, array $body, array $server = []): void
    {
        $this->client->request('PUT', $uri, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json'] + $server);
    }
}
