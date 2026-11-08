<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\SetIngredientParent\SetIngredientParentCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

/**
 * `PUT /api/v1/ingredients/{slug}/parent` (ADR 0038, SUB-001) avec un double de bus : la commande construite, le statut, le message de chaque
 * refus et le corps. Le parcours de bout en bout, avec la vraie base, est `IngredientPartApiFlowTest`.
 */
class SetIngredientParentApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandForTheOwnerOfTheIngredient(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $yolk = $this->savedIngredient($owner);

        // When
        $this->put($yolk->getSlug(), $owner, ['parent' => 'oeuf-1', 'ratio' => 2]);

        // Then : la partie vient du chemin, le parent et le ratio du corps, l'utilisateur du jeton
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new SetIngredientParentCommand($yolk->getSlug(), 'oeuf-1', 2.0, $owner->getId())], $commandBus->dispatched());
    }

    public function testADecimalRatioIsPassedAsAFloat(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $yolk = $this->savedIngredient($owner);

        // When
        $this->put($yolk->getSlug(), $owner, ['parent' => 'oeuf-1', 'ratio' => 0.5]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new SetIngredientParentCommand($yolk->getSlug(), 'oeuf-1', 0.5, $owner->getId())], $commandBus->dispatched());
    }

    public function testRefusesTheIngredientOfAnotherUserEvenToAnAdministratorBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $yolk = $this->savedIngredient($this->savedUser());

        // When
        $this->put($yolk->getSlug(), $this->savedUser(admin: true), ['parent' => 'oeuf-1', 'ratio' => 1]);

        // Then : pas de passe-droit
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    #[DataProvider('handlerRefusals')]
    public function testTheRefusalsOfTheHandlerKeepTheirStatusAndMessage(\Throwable $exception, int $status, string $message): void
    {
        // Given
        $commandBus = $this->mockCommandBus()->willThrow(SetIngredientParentCommand::class, $exception);
        $owner = $this->savedUser();
        $yolk = $this->savedIngredient($owner);

        // When
        $this->put($yolk->getSlug(), $owner, ['parent' => 'oeuf-1', 'ratio' => 1]);

        // Then
        $this->assertSame([$status, ['error' => ['message' => $message]]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertCount(1, $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function handlerRefusals(): iterable
    {
        yield 'parent inconnu ou d\'un autre' => [IngredientException::notFound('oeuf-1'), 404, "Ingredient 'oeuf-1' not found"];
        yield 'lui-même' => [IngredientException::partOfItself(), 422, 'INGREDIENT_PART_OF_ITSELF'];
        yield 'chaîne' => [IngredientException::partChain(), 422, 'INGREDIENT_PART_CHAIN'];
        yield 'onzième partie' => [IngredientException::partLimitReached(), 422, 'INGREDIENT_PART_LIMIT_REACHED'];
        yield 'ratio refusé par le modèle' => [IngredientException::partRatioInvalid(), 422, 'INGREDIENT_PART_RATIO_INVALID'];
        yield 'ligne dans une liste' => [IngredientException::partInOpenList(), 409, 'INGREDIENT_PART_IN_LIST'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBodyAndDispatchesNothing(array $body, string $message): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $yolk = $this->savedIngredient($owner);

        // When
        $this->put($yolk->getSlug(), $owner, $body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString($message, $this->jsonBody()['error']['message']);
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        $ratio = 'ratio: INGREDIENT_PART_RATIO_INVALID';
        yield 'sans corps' => [[], 'parent'];
        yield 'sans parent' => [['ratio' => 1], 'parent'];
        yield 'parent vide' => [['parent' => '', 'ratio' => 1], 'parent'];
        yield 'parent nul' => [['parent' => null, 'ratio' => 1], 'parent'];
        yield 'parent de plus de 255 caractères' => [['parent' => str_repeat('a', 256), 'ratio' => 1], 'parent'];
        yield 'ratio nul' => [['parent' => 'oeuf-1', 'ratio' => null], $ratio];
        yield 'ratio zéro' => [['parent' => 'oeuf-1', 'ratio' => 0], $ratio];
        yield 'ratio négatif' => [['parent' => 'oeuf-1', 'ratio' => -1], $ratio];
        yield 'ratio énorme' => [['parent' => 'oeuf-1', 'ratio' => 1000001], $ratio];
        yield 'ratio minuscule' => [['parent' => 'oeuf-1', 'ratio' => 0.0000001], $ratio];
        yield 'ratio texte' => [['parent' => 'oeuf-1', 'ratio' => 'deux'], $ratio];
        yield 'ratio texte numérique' => [['parent' => 'oeuf-1', 'ratio' => '2'], $ratio];
        yield 'ratio booléen' => [['parent' => 'oeuf-1', 'ratio' => true], $ratio];
        yield 'ratio liste' => [['parent' => 'oeuf-1', 'ratio' => [1]], $ratio];
    }

    public function testAnUnknownIngredientIsNotFoundBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->put('inconnu', $this->savedUser(), ['parent' => 'oeuf-1', 'ratio' => 1]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', '/api/v1/ingredients/jaune/parent', content: '{"parent":"oeuf","ratio":1}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedIngredient(User $owner): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName("Jaune d'œuf")->withCreator($owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $slug, User $user, array $body): void
    {
        $this->client->request('PUT', '/api/v1/ingredients/' . $slug . '/parent', content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
