<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveRecipeApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheRemovalAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        // When
        $this->client->request('DELETE', '/api/v1/recipes/' . $recipe->getId(), server: $this->bearerFor($author));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new RemoveRecipeCommand($recipe->getId())], $commandBus->dispatched());
    }

    public function testRefusesARecipeOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser())->build();
        $this->persister->saveRecipe($recipe);

        // When
        $this->client->request('DELETE', '/api/v1/recipes/' . $recipe->getId(), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownRecipe(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/recipes/unknown-recipe', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/recipes/any-recipe');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
