<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AddIngredientToShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/active/ingredients/sel/increase', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new AddIngredientToShoppingListCommand($owner->getId(), 'sel')], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/active/ingredients/sel/increase');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testForwardsTheIfMatchVersionToTheCommand(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/shopping-lists/active/ingredients/sel/increase', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => '"8"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new AddIngredientToShoppingListCommand($owner->getId(), 'sel', 8)], $commandBus->dispatched());
    }
}
