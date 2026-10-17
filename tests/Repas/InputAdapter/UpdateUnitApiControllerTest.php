<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\UpdateUnit\UpdateUnitCommand;
use Repas\Shared\Domain\Model\Dimension;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateUnitApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorReplacesItWithoutTouchingTheMeasureWhenTheBodyDoesNotMentionIt(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => 'Pincée', 'symbol' => 'pin']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUnitCommand('pincee', 'Pincée', 'pin', replaceMeasure: false)], $commandBus->dispatched());
    }

    public function testTheBodyReplacesTheMeasureWhenItGivesIt(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => 'Pincée', 'symbol' => 'pin', 'dimension' => 'WEIGHT', 'baseFactor' => 0.5]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUnitCommand('pincee', 'Pincée', 'pin', true, Dimension::WEIGHT, 0.5)], $commandBus->dispatched());
    }

    public function testAnExplicitNullClearsTheMeasure(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => 'Pincée', 'symbol' => 'pin', 'dimension' => null, 'baseFactor' => null]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUnitCommand('pincee', 'Pincée', 'pin', true, null, null)], $commandBus->dispatched());
    }

    public function testMentioningOnlyOneFieldReplacesThePairWithTheOtherAbsent(): void
    {
        // Given : la cohérence de la paire est jugée par le domaine (422), pas par le contrôleur
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), ['name' => 'Pincée', 'symbol' => 'pin', 'baseFactor' => 1000]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUnitCommand('pincee', 'Pincée', 'pin', true, null, 1000.0)], $commandBus->dispatched());
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

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($this->savedUser(admin: true), $body);

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
        yield 'dimension inconnue' => [['dimension' => 'MASS', 'baseFactor' => 1] + $valid];
        yield 'facteur nul' => [['dimension' => 'WEIGHT', 'baseFactor' => 0] + $valid];
        yield 'facteur négatif' => [['dimension' => 'WEIGHT', 'baseFactor' => -1] + $valid];
        yield 'facteur de mauvais type' => [['dimension' => 'WEIGHT', 'baseFactor' => 'beaucoup'] + $valid];
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
