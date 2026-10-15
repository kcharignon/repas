<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveMealFromPlanApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();

        // When
        $this->client->request('DELETE', '/api/v1/shopping-lists/active/recipes/recipe-1', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new removeMealFromPlanCommand($owner->getId(), 'recipe-1')], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/shopping-lists/active/recipes/recipe-1');

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
        $this->client->request('DELETE', '/api/v1/shopping-lists/active/recipes/recipe-1', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => '"5"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new removeMealFromPlanCommand($owner->getId(), 'recipe-1', 5)], $commandBus->dispatched());
    }
}
