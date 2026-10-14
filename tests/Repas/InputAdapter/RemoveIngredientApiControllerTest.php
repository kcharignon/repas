<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\RemoveIngredient\RemoveIngredientCommand;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveIngredientApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheRemovalAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        // When
        $this->client->request('DELETE', '/api/v1/ingredients/' . $ingredient->getSlug(), server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RemoveIngredientCommand($ingredient->getSlug())], $commandBus->dispatched());
    }

    public function testRefusesAnIngredientOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($ingredient);

        // When
        $this->client->request('DELETE', '/api/v1/ingredients/' . $ingredient->getSlug(), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesACommonIngredientToAUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $common = new IngredientBuilder()->isFlour()->build();
        $this->persister->saveIngredient($common);

        // When
        $this->client->request('DELETE', '/api/v1/ingredients/' . $common->getSlug(), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownIngredient(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/ingredients/unknown', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/ingredients/any');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
