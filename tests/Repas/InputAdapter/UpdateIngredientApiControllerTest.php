<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
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
        $this->assertEquals([new UpdateIngredientCommand($ingredient->getSlug(), 'Farine complète', null, 'epicerie', 'gramme', 'kilo', 1000.0, $owner->getId())], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?bool}>
     */
    public static function baseMarkerBodies(): iterable
    {
        yield 'vrai' => [['isBase' => true], true];
        yield 'faux' => [['isBase' => false], false];
        yield 'null explicite : inchangé' => [['isBase' => null], null];
        yield 'champ absent (ancien client) : inchangé' => [[], null];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('baseMarkerBodies')]
    public function testTheBaseMarkerIsOptionalAndPassedAsSent(array $extra, ?bool $expected): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, $extra + self::BODY);

        // Then : la commande porte l'utilisateur du jeton (jamais celui du corps) et le marqueur tel qu'envoyé, `null` quand il est absent
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateIngredientCommand($ingredient->getSlug(), 'Farine complète', null, 'epicerie', 'gramme', 'kilo', 1000.0, $owner->getId(), $expected)], $commandBus->dispatched());
    }

    public function testRefusesABaseMarkerThatIsNotABoolean(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, ['isBase' => 'oui'] + self::BODY);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnOrdinaryUserWhoMarksHisIngredientGetsAForbiddenWithAStableCode(): void
    {
        // Given : le handler refuse (seul le propriétaire du catalogue pose le marqueur)
        $commandBus = $this->mockCommandBus()->willThrow(UpdateIngredientCommand::class, IngredientException::baseMarkerForbidden());
        $owner = $this->savedUser();
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->put($ingredient->getSlug(), $owner, ['isBase' => true] + self::BODY);

        // Then : 403, le code en majuscules est le message
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'INGREDIENT_BASE_FORBIDDEN']], $this->jsonBody());
        $this->assertCount(1, $commandBus->dispatched());
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

    public function testAnAdministratorChangesHisOwnIngredientAsAnyoneElse(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $admin = $this->savedUser(admin: true);
        $mine = new IngredientBuilder()->isFlour()->withCreator($admin)->build();
        $this->persister->saveIngredient($mine);

        // When
        $this->put($mine->getSlug(), $admin, self::BODY);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testAnAdministratorHasNoPassThroughOnTheIngredientOfAnotherUser(): void
    {
        // Given : l'ingrédient est à un autre compte (le propriétaire du catalogue, par exemple) : le rôle n'y change rien (D3)
        $commandBus = $this->mockCommandBus();
        $theirs = new IngredientBuilder()->isFlour()->withCreator($this->savedUser(admin: true))->build();
        $this->persister->saveIngredient($theirs);

        // When
        $this->put($theirs->getSlug(), $this->savedUser(admin: true), self::BODY);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnIngredientWithoutAnOwnerToAnyone(): void
    {
        // Given : la forme de la base d'avant la passation, plus traitée à part : personne n'en est le propriétaire
        $commandBus = $this->mockCommandBus();
        $legacy = new IngredientBuilder()->isFlour()->withoutCreator()->build();
        $this->persister->saveIngredient($legacy);

        // When
        $this->put($legacy->getSlug(), $this->savedUser(admin: true), self::BODY);

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
