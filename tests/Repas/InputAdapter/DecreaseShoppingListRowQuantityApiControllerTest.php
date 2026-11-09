<?php

namespace Repas\Tests\Repas\InputAdapter;

use Repas\Repas\Application\Command\DecreaseShoppingListRowQuantity\DecreaseShoppingListRowQuantityCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class DecreaseShoppingListRowQuantityApiControllerTest extends QueryBusWebTestCase
{
    public function testDispatchesTheCommandAndReturnsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $rowId = $this->savedRowOf($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-list-rows/' . $rowId . '/decrease', server: $this->bearerFor($owner));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new DecreaseShoppingListRowQuantityCommand($rowId)], $commandBus->dispatched());
    }

    public function testPassesTheVersionTheClientHasRead(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $owner = $this->savedUser();
        $rowId = $this->savedRowOf($owner);

        // When
        $this->client->request('POST', '/api/v1/shopping-list-rows/' . $rowId . '/decrease', server: $this->bearerFor($owner) + ['HTTP_IF_MATCH' => '"8"']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new DecreaseShoppingListRowQuantityCommand($rowId, 8)], $commandBus->dispatched());
    }

    public function testRefusesARowOfAnotherUser(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();
        $rowId = $this->savedRowOf($this->savedUser());

        // When : une ligne d'un autre utilisateur est refusée
        $this->client->request('POST', '/api/v1/shopping-list-rows/' . $rowId . '/decrease', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testReturns404ForAnUnknownRow(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-list-rows/unknown-row/decrease', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('POST', '/api/v1/shopping-list-rows/unknown-row/decrease');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedRowOf(object $owner): string
    {
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($pasta);
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addIngredient($pasta)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);

        return $shoppingList->getRows()->reset()->getId();
    }
}
