<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
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
        $this->assertEquals([new CreateIngredientCommand('Praliné', '', 'epicerie', 'gramme', 'kilo', $user->getId(), 1000.0)], $commandBus->dispatched());
        $this->assertSame('/api/v1/ingredients/praline-user', $this->client->getResponse()->headers->get('Location'));
    }

    public function testAnAdministratorDispatchesTheSameCommandAsAnyUser(): void
    {
        // Given : le contrôleur ne transmet plus le rôle (la commande n'a plus de champ `isAdmin`), la création est la même pour tous
        $commandBus = $this->mockCommandBus()->willReturn(CreateIngredientCommand::class, 'praline-admin');
        $admin = $this->savedUser(admin: true);

        // When
        $this->post($admin, ['name' => 'Praliné', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000, 'isAdmin' => true]);

        // Then : même un corps qui annonce `isAdmin` ne change rien
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateIngredientCommand('Praliné', '', 'epicerie', 'gramme', 'kilo', $admin->getId(), 1000.0)], $commandBus->dispatched());
    }

    public function testAnIngredientOfTheSameNameIsAConflict(): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willThrow(CreateIngredientCommand::class, IngredientException::nameAlreadyUsedInDepartment());

        // When
        $this->post($this->savedUser(), ['name' => 'Praliné', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme']);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertNull($this->client->getResponse()->headers->get('Location'));
        $this->assertCount(1, $commandBus->dispatched());
    }
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function baseMarkerBodies(): iterable
    {
        yield 'vrai' => [['isBase' => true], true];
        yield 'faux' => [['isBase' => false], false];
        yield 'null explicite : faux' => [['isBase' => null], false];
        yield 'champ absent (ancien client) : faux' => [[], false];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('baseMarkerBodies')]
    public function testTheBaseMarkerIsOptionalAndNeverTrueByDefault(array $extra, bool $expected): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willReturn(CreateIngredientCommand::class, 'praline-user');
        $user = $this->savedUser();

        // When
        $this->post($user, $extra + ['name' => 'Praliné', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new CreateIngredientCommand('Praliné', '', 'epicerie', 'gramme', 'kilo', $user->getId(), 1000.0, $expected)], $commandBus->dispatched());
    }

    public function testRefusesABaseMarkerThatIsNotABoolean(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($this->savedUser(), ['name' => 'Sel', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme', 'isBase' => 'oui']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnOrdinaryUserWhoCreatesABaseIngredientGetsAForbiddenWithAStableCode(): void
    {
        // Given : le handler refuse (seul le propriétaire du catalogue pose le marqueur) et rien n'est créé
        $commandBus = $this->mockCommandBus()->willThrow(CreateIngredientCommand::class, IngredientException::baseMarkerForbidden());

        // When
        $this->post($this->savedUser(), ['name' => 'Sel', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme', 'isBase' => true]);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'INGREDIENT_BASE_FORBIDDEN']], $this->jsonBody());
        $this->assertNull($this->client->getResponse()->headers->get('Location'));
        $this->assertCount(1, $commandBus->dispatched());
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
