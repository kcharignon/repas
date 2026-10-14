<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class CreateIngredientApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandForTheCurrentUserAndReturnsTheLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateIngredientCommand::class, 'praline-user');
        $user = $this->savedUser();

        // When
        $this->post($user, ['name' => 'Praliné', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateIngredientCommand('Praliné', '', 'epicerie', 'gramme', 'kilo', $user->getId(), false, 1000.0)], $commandBus->dispatched());
        $this->assertSame('/api/v1/ingredients/praline-user', $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheCoefficientIsOptional(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateIngredientCommand::class, 'sel');
        $user = $this->savedUser();

        // When
        $this->post($user, ['name' => 'Sel', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme']);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertNull($commandBus->dispatched()[0]->coefficient);
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
        $this->post($this->savedUser(), $body);

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
        $valid = ['name' => 'Sel', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000];

        yield 'sans nom' => [['name' => ''] + $valid];
        yield 'nom trop long' => [['name' => str_repeat('a', 256)] + $valid];
        yield 'sans rayon' => [['departmentSlug' => ''] + $valid];
        yield 'sans unité de recette' => [['defaultCookingUnitSlug' => ''] + $valid];
        yield "sans unité d'achat" => [['defaultPurchaseUnitSlug' => ''] + $valid];
        yield 'coefficient nul' => [['coefficient' => 0] + $valid];
        yield 'coefficient négatif' => [['coefficient' => -2] + $valid];
        yield 'coefficient de mauvais type' => [['coefficient' => 'beaucoup'] + $valid];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/ingredients', content: '{}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/ingredients', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
