<?php

namespace Repas\Tests\Repas\InputAdapter;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\UpdateShoppingListType\UpdateShoppingListTypeCommand;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateShoppingListTypeApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandForTheOwnerWithTheCompleteSetOfDepartments(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $type = $this->savedType($owner);

        // When
        $this->put($type->getId(), $owner, ['name' => 'Charcuterie', 'departmentSlugs' => ['viandes', 'fromages']]);

        // Then : le type vient du chemin et l'utilisateur du jeton
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateShoppingListTypeCommand($type->getId(), 'Charcuterie', ['viandes', 'fromages'], $owner->getId())], $commandBus->dispatched());
    }

    public function testAnEmptySetOfDepartmentsDetachesThemAll(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $type = $this->savedType($owner);

        // When
        $this->put($type->getId(), $owner, ['name' => 'Boucherie', 'departmentSlugs' => []]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateShoppingListTypeCommand($type->getId(), 'Boucherie', [], $owner->getId())], $commandBus->dispatched());
    }

    public function testRefusesTheTypeOfAnotherUserEvenToAnAdministratorBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $type = $this->savedType($this->savedUser());

        // When
        $this->put($type->getId(), $this->savedUser(admin: true), ['name' => 'Charcuterie', 'departmentSlugs' => []]);

        // Then : pas de passe-droit
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnUnknownTypeIsNotFoundBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34', $this->savedUser(), ['name' => 'Charcuterie', 'departmentSlugs' => []]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    #[DataProvider('handlerRefusals')]
    public function testTheRefusalsOfTheHandlerKeepTheirStatusAndCode(\Throwable $exception, int $status, string $message): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willThrow(UpdateShoppingListTypeCommand::class, $exception);
        $owner = $this->savedUser();
        $type = $this->savedType($owner);

        // When
        $this->put($type->getId(), $owner, ['name' => 'Charcuterie', 'departmentSlugs' => ['inconnu']]);

        // Then
        $this->assertSame([$status, ['error' => ['message' => $message]]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertCount(1, $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function handlerRefusals(): iterable
    {
        yield 'nom déjà pris' => [ShoppingListTypeException::nameAlreadyUsed(), 409, 'LIST_TYPE_NAME_ALREADY_USED'];
        yield 'nom invalide' => [ShoppingListTypeException::invalidName(), 422, 'LIST_TYPE_NAME_INVALID'];
        yield 'rayon inconnu' => [DepartmentException::notFound(), 404, 'DEPARTMENT_NOT_FOUND'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBodyAndDispatchesNothing(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $type = $this->savedType($owner);

        // When
        $this->put($type->getId(), $owner, $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans corps' => [[]];
        yield 'sans nom' => [['departmentSlugs' => []]];
        yield 'nom vide' => [['name' => '', 'departmentSlugs' => []]];
        yield 'nom de 41 caractères' => [['name' => str_repeat('a', 41), 'departmentSlugs' => []]];
        yield 'sans rayons' => [['name' => 'Boucherie']];
        yield 'rayons nuls' => [['name' => 'Boucherie', 'departmentSlugs' => null]];
        yield 'rayons qui sont un texte' => [['name' => 'Boucherie', 'departmentSlugs' => 'viandes']];
        yield 'un rayon qui est un nombre' => [['name' => 'Boucherie', 'departmentSlugs' => ['viandes', 12]]];
        yield 'un rayon vide' => [['name' => 'Boucherie', 'departmentSlugs' => ['']]];
        yield 'un rayon qui est une liste' => [['name' => 'Boucherie', 'departmentSlugs' => [['viandes']]]];
        yield 'trop de rayons' => [['name' => 'Boucherie', 'departmentSlugs' => array_map(static fn(int $i): string => "rayon-$i", range(1, 201))]];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/shopping-list-types/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34', content: '{"name":"Boucherie","departmentSlugs":[]}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedType(User $owner): ShoppingListType
    {
        $type = ShoppingListType::create('3f1c2a7e-8d41-4c62-9b0a-' . substr(md5($owner->getId()), 0, 12), $owner->getId(), 'Boucherie', new DateTimeImmutable('2026-10-31 10:00:00'));
        static::getContainer()->get(ShoppingListTypeRepository::class)->save($type);

        return $type;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $id, User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/shopping-list-types/' . $id, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
