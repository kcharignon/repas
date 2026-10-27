<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateIngredientConversion\CreateIngredientConversionCommand;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class CreateIngredientConversionApiControllerTest extends QueryBusWebTestCase
{
    private const array BODY = ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'gramme', 'coefficient' => 125];

    public function testDispatchesTheCommandAndReturnsTheLocationOfTheNewConversion(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->post($ingredient->getSlug(), $owner, self::BODY);

        // Then : l'ingrédient vient du chemin et l'utilisateur du jeton, jamais du corps
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $dispatched = $commandBus->dispatched();
        $this->assertCount(1, $dispatched);
        $command = $dispatched[0];
        $this->assertInstanceOf(CreateIngredientConversionCommand::class, $command);
        $this->assertSame([$ingredient->getSlug(), 'tasse', 'gramme', 125.0, $owner->getId()], [$command->ingredientSlug, $command->startUnitSlug, $command->endUnitSlug, $command->coefficient, $command->userId]);
        $this->assertSame('/api/v1/ingredients/' . $ingredient->getSlug() . '/conversions/' . $command->id, $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAnIngredientOfAnotherUserEvenToAnAdministrator(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $theirs = $this->savedIngredient($this->savedUser());

        // When
        $this->post($theirs->getSlug(), $this->savedUser(admin: true), self::BODY);

        // Then : pas de passe-droit
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownIngredient(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post('inconnu', $this->savedUser(), self::BODY);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAPairTheIngredientAlreadyHasIsAConflictWithAStableCode(): void
    {
        // Given : le handler refuse
        $commandBus = $this->mockCommandBus()->willThrow(CreateIngredientConversionCommand::class, ConversionException::alreadyExists());
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->post($ingredient->getSlug(), $owner, self::BODY);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CONVERSION_ALREADY_EXISTS']], $this->jsonBody());
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testTwoIdenticalUnitsAreAnUnprocessableEntityWithAStableCode(): void
    {
        // Given
        $this->mockCommandBus()->willThrow(CreateIngredientConversionCommand::class, ConversionException::sameUnit());
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->post($ingredient->getSlug(), $owner, ['endUnitSlug' => 'tasse'] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CONVERSION_SAME_UNIT']], $this->jsonBody());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'coefficient nul' => [['coefficient' => 0] + self::BODY];
        yield 'coefficient négatif' => [['coefficient' => -2] + self::BODY];
        yield 'unité de départ vide' => [['startUnitSlug' => ''] + self::BODY];
        yield 'unité d\'arrivée absente' => [['startUnitSlug' => 'tasse', 'coefficient' => 3]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->post($ingredient->getSlug(), $owner, $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/ingredients/any/conversions', content: json_encode(self::BODY), server: ['CONTENT_TYPE' => 'application/json']);

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
    private function post(string $slug, User $user, array $body): void
    {
        $this->client->request('POST', '/api/v1/ingredients/' . $slug . '/conversions', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
