<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\UpdateIngredientConversion\UpdateIngredientConversionCommand;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateIngredientConversionApiControllerTest extends QueryBusWebTestCase
{
    private const array BODY = ['startUnitSlug' => 'cuillere-a-soupe', 'endUnitSlug' => 'gramme', 'coefficient' => 12];

    public function testDispatchesTheReplacement(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), 'conversion-1', $owner, self::BODY);

        // Then : l'ingrédient et la conversion viennent du chemin, l'utilisateur du jeton
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateIngredientConversionCommand('conversion-1', $ingredient->getSlug(), 'cuillere-a-soupe', 'gramme', 12.0, $owner->getId())], $commandBus->dispatched());
    }

    public function testRefusesAnIngredientOfAnotherUserEvenToAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $theirs = $this->savedIngredient($this->savedUser());

        // When
        $this->put($theirs->getSlug(), 'conversion-1', $this->savedUser(admin: true), self::BODY);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAConversionTheIngredientNeedsIsRefusedWithAStableCode(): void
    {
        // Given : le handler refuse
        $this->mockCommandBus()->willThrow(UpdateIngredientConversionCommand::class, ConversionException::requiredByIngredient());
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), 'conversion-1', $owner, self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CONVERSION_REQUIRED_BY_INGREDIENT']], $this->jsonBody());
    }

    public function testAnotherConversionOnTheSamePairIsAConflict(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(UpdateIngredientConversionCommand::class, ConversionException::alreadyExists());
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), 'conversion-1', $owner, self::BODY);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CONVERSION_ALREADY_EXISTS']], $this->jsonBody());
    }

    public function testAnUnknownOrUniversalConversionIsNotFound(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(UpdateIngredientConversionCommand::class, ConversionException::notFound('conversion-1'));
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), 'conversion-1', $owner, self::BODY);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRefusesAnInvalidBody(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), 'conversion-1', $owner, ['coefficient' => 0] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/ingredients/any/conversions/c', content: json_encode(self::BODY), server: ['CONTENT_TYPE' => 'application/json']);

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
    private function put(string $slug, string $conversionId, User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/ingredients/' . $slug . '/conversions/' . $conversionId, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
