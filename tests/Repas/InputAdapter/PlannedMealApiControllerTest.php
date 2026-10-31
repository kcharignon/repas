<?php

namespace Repas\Tests\Repas\InputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealList;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealResult;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class PlannedMealApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsTheListsThatReceivedSomething(): void
    {
        // Given : la recette a rempli la liste principale (sans nom : sa date la nomme) et la liste « Boucherie »
        $commandBus = $this->mockCommandBus();
        $commandBus->willReturn(PlannedMealCommand::class, new PlannedMealResult([
            new PlannedMealList('main-id', null, new DateTimeImmutable('2026-01-15T10:00:00+00:00'), null),
            new PlannedMealList('butcher-id', 'Boucherie', new DateTimeImmutable('2026-10-31T09:00:00+00:00'), 'butcher-type'),
        ]));
        $owner = $this->savedUser();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active/recipes/recipe-1', server: $this->bearerFor($owner));

        // Then : 200 et, par liste, exactement trois clés (jamais la date de création)
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(
            ['lists' => [
                ['id' => 'main-id', 'name' => '15 janvier 2026', 'typeId' => null],
                ['id' => 'butcher-id', 'name' => 'Boucherie', 'typeId' => 'butcher-type'],
            ]],
            json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertEquals([new PlannedMealCommand($owner->getId(), 'recipe-1', $owner->getDefaultServing())], $commandBus->dispatched());
    }

    public function testAnAccountWithoutTypeGetsItsMainListAlone(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $commandBus->willReturn(PlannedMealCommand::class, new PlannedMealResult([new PlannedMealList('main-id', 'Courses', new DateTimeImmutable('2026-01-15T10:00:00+00:00'), null)]));
        $owner = $this->savedUser();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active/recipes/recipe-1', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['lists' => [['id' => 'main-id', 'name' => 'Courses', 'typeId' => null]]], json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
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

    public function testForwardsTheIfMatchVersionToTheCommand(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $commandBus->willReturn(PlannedMealCommand::class, new PlannedMealResult([]));
        $owner = $this->savedUser();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active/recipes/recipe-1', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => '"5"']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new PlannedMealCommand($owner->getId(), 'recipe-1', $owner->getDefaultServing(), 5)], $commandBus->dispatched());
    }

    public function testARecipeAlreadyInAListIsRefusedWithAConflict(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $commandBus->willThrow(PlannedMealCommand::class, ShoppingListException::recipeAlreadyInList('Hachis parmentier'));
        $owner = $this->savedUser();

        // When
        $this->client->request('PUT', '/api/v1/shopping-lists/active/recipes/recipe-1', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Recipe (Hachis parmentier) already present in list']], json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
