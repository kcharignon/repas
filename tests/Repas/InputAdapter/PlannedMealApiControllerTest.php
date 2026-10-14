<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class PlannedMealApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active/recipes/recipe-1', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new PlannedMealCommand($owner->getId(), 'recipe-1')], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active/recipes/recipe-1');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
