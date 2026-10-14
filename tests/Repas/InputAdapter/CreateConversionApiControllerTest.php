<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateConversion\CreateConversionCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class CreateConversionApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorCreatesAConversionAndGetsItsLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(admin: true), ['startUnitSlug' => 'plaquette', 'endUnitSlug' => 'gramme', 'coefficient' => 250, 'ingredientSlug' => 'beurre']);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(CreateConversionCommand::class, $command);
        $this->assertSame(['plaquette', 'gramme', 250.0, 'beurre'], [$command->startUnitSlug, $command->endUnitSlug, $command->coefficient, $command->ingredientSlug]);
        $this->assertSame('/api/v1/conversions/' . $command->id, $this->client->getResponse()->headers->get('Location'));
    }

    public function testACommonConversionHasNoIngredient(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(admin: true), ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 1000]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertNull($commandBus->dispatched()[0]->ingredientSlug);
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 1000]);

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
        $valid = ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 1000];

        yield "sans unité de départ" => [['startUnitSlug' => ''] + $valid];
        yield "sans unité d'arrivée" => [['endUnitSlug' => ''] + $valid];
        yield 'coefficient nul' => [['coefficient' => 0] + $valid];
        yield 'coefficient de mauvais type' => [['coefficient' => 'beaucoup'] + $valid];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/conversions', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/conversions', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
