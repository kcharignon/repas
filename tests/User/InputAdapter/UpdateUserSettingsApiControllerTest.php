<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsCommand;
use Repas\User\Domain\Model\User;

class UpdateUserSettingsApiControllerTest extends QueryBusWebTestCase
{
    public function testTheConnectedUserChangesHisServingAndPassword(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['defaultServing' => 6, 'newPassword' => 'un-nouveau-mot-de-passe', 'actualPassword' => 'test']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUserSettingsCommand($user->getId(), 'un-nouveau-mot-de-passe', 6, 'test')], $commandBus->dispatched());
    }

    public function testTheServingAloneLeavesThePasswordUntouched(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['defaultServing' => 2]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUserSettingsCommand($user->getId(), null, 2, null)], $commandBus->dispatched());
    }

    public function testTheUserComesFromTheTokenNeverFromTheBody(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['defaultServing' => 2, 'userId' => $other->getId(), 'id' => $other->getId()]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUserSettingsCommand($user->getId(), null, 2, null)], $commandBus->dispatched());
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
        $this->put($this->savedUser(), $body);

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
        yield 'sans nombre de personnes' => [['newPassword' => 'un-nouveau-mot-de-passe']];
        yield 'aucune personne' => [['defaultServing' => 0]];
        yield 'trop de personnes' => [['defaultServing' => 101]];
        yield 'nouveau mot de passe trop court' => [['defaultServing' => 2, 'newPassword' => 'court', 'actualPassword' => 'test']];
        yield 'nouveau mot de passe vide' => [['defaultServing' => 2, 'newPassword' => '', 'actualPassword' => 'test']];
        yield 'nouveau mot de passe trop long' => [['defaultServing' => 2, 'newPassword' => str_repeat('a', 4097), 'actualPassword' => 'test']];
        yield 'mot de passe actuel de mauvais type' => [['defaultServing' => 2, 'actualPassword' => ['x']]];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/me/settings', content: '{"defaultServing": 2}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/me/settings', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
