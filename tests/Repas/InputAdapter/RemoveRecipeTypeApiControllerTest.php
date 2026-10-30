<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\RemoveRecipeType\RemoveRecipeTypeCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class RemoveRecipeTypeApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorRemovesItAndNamesTheTypeThatReceivesItsRecipes(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->delete($this->savedUser(admin: true), '?transferTo=plat');

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RemoveRecipeTypeCommand('aperitif', 'plat')], $commandBus->dispatched());
    }

    public function testRefusesARequestWithoutTheTargetType(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $admin = $this->savedUser(admin: true);

        // When & Then
        foreach (['', '?transferTo=', '?other=plat'] as $query) {
            $this->delete($admin, $query);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $query);
        }
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->delete($this->savedUser(), '?transferTo=plat');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/recipe-types/aperitif?transferTo=plat');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function delete(User $user, string $query): void
    {
        $this->client->request('DELETE', '/api/v1/recipe-types/aperitif' . $query, server: $this->bearerFor($user));
    }
}
