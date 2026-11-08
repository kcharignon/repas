<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Command\RemoveIngredientParent\RemoveIngredientParentCommand;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

/**
 * `DELETE /api/v1/ingredients/{slug}/parent` (ADR 0038, SUB-001) avec un double de bus. Le parcours de bout en bout est `IngredientPartApiFlowTest`.
 */
class RemoveIngredientParentApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheRemovalForTheOwnerOfTheIngredient(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $yolk = $this->savedIngredient($owner);

        // When
        $this->delete($yolk->getSlug(), $owner);

        // Then : la partie vient du chemin, l'utilisateur du jeton
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new RemoveIngredientParentCommand($yolk->getSlug(), $owner->getId())], $commandBus->dispatched());
    }

    public function testRefusesTheIngredientOfAnotherUserEvenToAnAdministratorBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $yolk = $this->savedIngredient($this->savedUser());

        // When
        $this->delete($yolk->getSlug(), $this->savedUser(admin: true));

        // Then : pas de passe-droit
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnUnknownIngredientIsNotFoundBeforeDispatching(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->delete('inconnu', $this->savedUser());

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('DELETE', '/api/v1/ingredients/jaune/parent');

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

    private function delete(string $slug, User $user): void
    {
        $this->client->request('DELETE', '/api/v1/ingredients/' . $slug . '/parent', server: $this->bearerFor($user));
    }
}
