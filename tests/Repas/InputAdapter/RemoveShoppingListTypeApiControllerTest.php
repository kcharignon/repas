<?php

namespace Repas\Tests\Repas\InputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Command\RemoveShoppingListType\RemoveShoppingListTypeCommand;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class RemoveShoppingListTypeApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandForTheOwner(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $type = $this->savedType($owner);

        // When
        $this->client->request('DELETE', '/api/v1/shopping-list-types/' . $type->getId(), server: $this->bearerFor($owner));

        // Then : le type vient du chemin et l'utilisateur du jeton
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RemoveShoppingListTypeCommand($type->getId(), $owner->getId())], $commandBus->dispatched());
    }

    public function testRefusesTheTypeOfAnotherUserEvenToAnAdministratorBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $type = $this->savedType($this->savedUser());

        // When
        $this->client->request('DELETE', '/api/v1/shopping-list-types/' . $type->getId(), server: $this->bearerFor($this->savedUser(admin: true)));

        // Then : pas de passe-droit
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnUnknownTypeIsNotFoundBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/shopping-list-types/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/shopping-list-types/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34');

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
}
