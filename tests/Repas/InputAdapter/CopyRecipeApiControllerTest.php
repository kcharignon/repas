<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Tests\Helper\QueryBusWebTestCase;

class CopyRecipeApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCopyForTheCurrentUserAndReturnsTheLocationOfTheCopy(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $user = $this->savedUser();

        // When
        $this->client->request('POST', '/api/v1/recipes/original-recipe/copy', server: $this->bearerFor($user));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(CopyRecipeCommand::class, $command);
        $this->assertSame(['original-recipe', $user->getId()], [$command->recipeId, $command->authorId]);
        $this->assertSame('/api/v1/recipes/' . $command->newRecipeId, $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/recipes/original-recipe/copy');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
