<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Les valeurs qui ne sont pas un nombre entier de personnes, écrites telles qu'elles arrivent dans le corps.
     *
     * @return iterable<string, array{string}>
     */
    public static function servingsThatAreNotWholeNumbers(): iterable
    {
        yield 'une chaîne' => ['"beaucoup"'];
        yield 'un décimal' => ['2.5'];
        yield 'un décimal sans reste' => ['2.0'];
        yield 'un entier en texte' => ['"2"'];
        yield 'booléen' => ['true'];
        yield 'nul' => ['null'];
        yield 'un tableau' => ['[2]'];
    }

    #[DataProvider('servingsThatAreNotWholeNumbers')]
    public function testRefusesAServingThatIsNotAWholeNumber(string $serving): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($owner)->build();
        $this->persister->saveRecipe($recipe);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);
        $mealId = $shoppingList->getMeals()->reset()->getId();

        // When : sans la moindre dépréciation de PHP
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: sprintf('{"serving":%s}', $serving), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json']);
        } finally {
            restore_error_handler();
        }

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'serving: Le nombre de personnes doit être un nombre entier de 1 à 100.']], $this->jsonBody());
        $this->assertSame([], $deprecations);
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

    public function testForwardsTheIfMatchVersionToTheCommand(): void
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
        $this->client->request('PUT', '/api/v1/meals/' . $mealId . '/serving', content: json_encode(['serving' => 6], JSON_THROW_ON_ERROR), server: $this->bearerFor($owner) + ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => '"3"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateServingMealCommand($mealId, 6, 3)], $commandBus->dispatched());
    }
}
