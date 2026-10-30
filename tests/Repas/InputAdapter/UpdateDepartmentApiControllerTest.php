<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('iconBodies')]
    public function testWithoutTheIconKeyTheIconIsNotTouchedWithTheKeyItIsReplacedOrErased(array $extra, ?string $icon, bool $changeIcon): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), $extra + ['name' => 'Surgelés', 'image' => 'images/frozen.svg']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateDepartmentCommand('surgeles', 'Surgelés', 'images/frozen.svg', $icon, $changeIcon)], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string, bool}>
     */
    public static function iconBodies(): iterable
    {
        yield 'une icône' => [['icon' => 'snowflake'], 'snowflake', true];
        yield 'null efface' => [['icon' => null], null, true];
        yield 'sans la clé (un ancien client) : inchangée' => [[], null, false];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('invalidIcons')]
    public function testRefusesAnInvalidIconAndWritesNothing(array $extra): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), $extra + ['name' => 'Surgelés', 'image' => 'images/frozen.svg']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidIcons(): iterable
    {
        yield 'inconnue' => [['icon' => 'sparkles']];
        yield 'vide' => [['icon' => '']];
        yield 'majuscules' => [['icon' => 'SNOWFLAKE']];
        yield 'nombre' => [['icon' => 12]];
        yield 'booléen' => [['icon' => true]];
        yield 'liste' => [['icon' => ['can']]];
    }

    public function testAUserWhoIsNotAnAdministratorCannotSetAnIcon(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), ['name' => 'Surgelés', 'image' => 'images/frozen.svg', 'icon' => 'snowflake']);

        // Then : `403`, rien de plus ouvert qu'avant
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
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
