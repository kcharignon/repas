<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateRecipeType\CreateRecipeTypeCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class CreateRecipeTypeApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorCreatesItAndGetsTheLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateRecipeTypeCommand::class, 'aperitif');

        // When
        $this->post($this->savedUser(admin: true), ['name' => 'Apéritif', 'image' => 'images/aperitif.svg', 'order' => 4]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateRecipeTypeCommand('Apéritif', 'images/aperitif.svg', 4)], $commandBus->dispatched());
        $this->assertSame('/api/v1/recipe-types/aperitif', $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), ['name' => 'Apéritif', 'image' => 'images/aperitif.svg', 'order' => 4]);

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
        $valid = ['name' => 'Apéritif', 'image' => 'images/aperitif.svg', 'order' => 4];

        yield 'sans nom' => [['name' => ''] + $valid];
        yield 'sans image' => [['image' => ''] + $valid];
        yield 'rang négatif' => [['order' => -1] + $valid];
        yield 'rang trop grand' => [['order' => 1001] + $valid];
        yield 'rang de mauvais type' => [['order' => 'premier'] + $valid];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/recipe-types', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/recipe-types', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
