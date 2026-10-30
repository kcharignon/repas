<?php

namespace Repas\Tests\Drive\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Drive\Application\Command\RememberLastRetailer\RememberLastRetailerCommand;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class RememberLastRetailerApiControllerTest extends QueryBusWebTestCase
{
    public function testTheConnectedUserRemembersTheRetailerHeOpened(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['retailer' => 'leclerc']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RememberLastRetailerCommand($user->getId(), Retailer::LECLERC)], $commandBus->dispatched());
    }

    public function testTheUserComesFromTheTokenNeverFromTheBody(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['userId' => $other->getId(), 'retailer' => 'leclerc']);

        // Then
        $this->assertEquals([new RememberLastRetailerCommand($user->getId(), Retailer::LECLERC)], $commandBus->dispatched());
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
        yield 'corps vide' => [[]];
        yield 'enseigne nulle' => [['retailer' => null]];
        yield 'enseigne inconnue' => [['retailer' => 'inconnue']];
        yield 'enseigne vide' => [['retailer' => '']];
        yield 'enseigne de mauvais type' => [['retailer' => ['leclerc']]];
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOffEvenWithAnInvalidBody(): void
    {
        // Given
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(), []);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/drive/last-retailer', content: '{"retailer":"leclerc"}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/drive/last-retailer', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
