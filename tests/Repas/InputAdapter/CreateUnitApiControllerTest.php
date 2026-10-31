<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateUnit\CreateUnitCommand;
use Repas\Repas\Domain\Model\Dimension;
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

    public function testForwardsTheDimensionAndTheBaseFactor(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateUnitCommand::class, 'kilo-bis');

        // When
        $this->post($this->savedUser(admin: true), ['name' => 'Kilo bis', 'symbol' => 'kg', 'dimension' => 'WEIGHT', 'baseFactor' => 1000]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateUnitCommand('Kilo bis', 'kg', Dimension::WEIGHT, 1000.0)], $commandBus->dispatched());
    }

    public function testAcceptsAnExplicitNullMeasure(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateUnitCommand::class, 'pincee');

        // When
        $this->post($this->savedUser(admin: true), ['name' => 'Pincée', 'symbol' => 'pin', 'dimension' => null, 'baseFactor' => null]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateUnitCommand('Pincée', 'pin', null, null)], $commandBus->dispatched());
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
        yield 'dimension inconnue' => [['dimension' => 'MASS', 'baseFactor' => 1] + $valid];
        yield 'dimension de mauvais type' => [['dimension' => 3, 'baseFactor' => 1] + $valid];
        yield 'facteur nul' => [['dimension' => 'WEIGHT', 'baseFactor' => 0] + $valid];
        yield 'facteur négatif' => [['dimension' => 'WEIGHT', 'baseFactor' => -1000] + $valid];
        yield 'facteur de mauvais type' => [['dimension' => 'WEIGHT', 'baseFactor' => 'beaucoup'] + $valid];
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
