<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class UpdateServingMealApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: json_encode(['serving' => 6], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateServingMealCommand($mealId, 6)], $commandBus->dispatched());
    }

    public function testRefusesAResourceOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: json_encode(['serving' => 6], JSON_THROW_ON_ERROR), server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownResource(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/meals/unknown-meal/serving', content: json_encode(['serving' => 6], JSON_THROW_ON_ERROR), server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAServingTooSmall(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: json_encode(['serving' => 0], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAServingTooBig(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: json_encode(['serving' => 101], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAServingOfTheWrongType(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: json_encode(['serving' => 'beaucoup'], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAServingMissing(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When
        $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: json_encode([], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/meals/unknown-meal/serving', content: json_encode(['serving' => 6], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }
}
