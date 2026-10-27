<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\DeleteIngredientConversion\DeleteIngredientConversionCommand;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class DeleteIngredientConversionApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheRemoval(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->delete($ingredient->getSlug(), 'conversion-1', $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new DeleteIngredientConversionCommand('conversion-1', $ingredient->getSlug(), $owner->getId())], $commandBus->dispatched());
    }

    public function testRefusesAnIngredientOfAnotherUserEvenToAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $theirs = $this->savedIngredient($this->savedUser());

        // When
        $this->delete($theirs->getSlug(), 'conversion-1', $this->savedUser(admin: true));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAConversionTheIngredientNeedsIsRefusedWithAStableCode(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(DeleteIngredientConversionCommand::class, ConversionException::requiredByIngredient());
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->delete($ingredient->getSlug(), 'conversion-1', $owner);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CONVERSION_REQUIRED_BY_INGREDIENT']], $this->jsonBody());
    }

    public function testAnUnknownOrUniversalConversionIsNotFound(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(DeleteIngredientConversionCommand::class, ConversionException::notFound('conversion-1'));
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->delete($ingredient->getSlug(), 'conversion-1', $owner);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/ingredients/any/conversions/c');

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

    private function delete(string $slug, string $conversionId, User $user): void
    {
        $this->client->request('DELETE', '/api/v1/ingredients/' . $slug . '/conversions/' . $conversionId, server: $this->bearerFor($user));
    }
}
