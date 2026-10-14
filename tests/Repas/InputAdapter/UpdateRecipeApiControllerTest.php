<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateRecipeApiControllerTest extends QueryBusWebTestCase
{
    private const array BODY = [
        'name' => 'Pâtes revisitées',
        'serving' => 6,
        'typeSlug' => 'plat',
        'rows' => [['ingredientSlug' => 'pate', 'unitSlug' => 'gramme', 'quantity' => 750]],
    ];

    public function testDispatchesTheReplacementAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateRecipeCommand(
            $recipe->getId(),
            'Pâtes revisitées',
            6,
            new Tab([new UpdateRecipeRowSubCommand('pate', 'gramme', 750)], UpdateRecipeRowSubCommand::class),
            'plat',
        )], $commandBus->dispatched());
    }

    public function testRefusesARecipeOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $recipe = $this->savedRecipe($this->savedUser());

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownRecipe(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('/api/v1/recipes/unknown-recipe', $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnEmptyListOfRows(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->put('/api/v1/recipes/' . $recipe->getId(), $author, ['rows' => []] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/recipes/any-recipe', content: json_encode(self::BODY), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedRecipe(User $author): \Repas\Repas\Domain\Model\Recipe
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $uri, User $user, array $body): void
    {
        $this->client->request('PUT', $uri, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }

    public function testForwardsTheIfMatchVersionToTheCommand(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->client->request('PUT', '/api/v1/recipes/' . $recipe->getId(), content: json_encode(self::BODY, JSON_THROW_ON_ERROR), server: $this->bearerFor($author) + ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => '"4"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(4, $commandBus->dispatched()[0]->expectedVersion);
    }

    public function testRefusesAnIfMatchWhichIsNotAnEtagWithoutDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $author = $this->savedUser();
        $recipe = $this->savedRecipe($author);

        // When
        $this->client->request('PUT', '/api/v1/recipes/' . $recipe->getId(), content: json_encode(self::BODY, JSON_THROW_ON_ERROR), server: $this->bearerFor($author) + ['CONTENT_TYPE' => 'application/json', 'HTTP_IF_MATCH' => 'abc']);

        // Then
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }
}
