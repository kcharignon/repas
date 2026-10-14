<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateIngredientApiControllerTest extends QueryBusWebTestCase
{
    private const array BODY = ['name' => 'Farine complète', 'departmentSlug' => 'epicerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'kilo', 'coefficient' => 1000];

    public function testDispatchesTheReplacementAndKeepsTheImage(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateIngredientCommand($ingredient->getSlug(), 'Farine complète', null, 'epicerie', 'gramme', 'kilo', 1000.0)], $commandBus->dispatched());
    }

    public function testRefusesAnIngredientOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $ingredient = $this->savedIngredient($this->savedUser());

        // When
        $this->put($ingredient->getSlug(), $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAUserCannotChangeACommonIngredientButAnAdministratorCan(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $common = new IngredientBuilder()->isFlour()->build();
        $this->persister->saveIngredient($common);

        // When
        $this->put($common->getSlug(), $this->savedUser(admin: true), self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testRefusesACommonIngredientToAUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $common = new IngredientBuilder()->isFlour()->build();
        $this->persister->saveIngredient($common);

        // When
        $this->put($common->getSlug(), $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownIngredient(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('unknown-ingredient', $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnInvalidBody(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, ['name' => ''] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/ingredients/any', content: json_encode(self::BODY), server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedIngredient(User $owner): Ingredient
    {
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $slug, User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/ingredients/' . $slug, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
