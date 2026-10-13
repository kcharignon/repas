<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Tests\Helper\Builder\DTO\ActiveShoppingListSummaryDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetActiveShoppingListSummaryApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheSummaryOfTheAuthenticatedUser(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()
            ->withShoppingListId('list-active')
            ->withQuantity('oeuf', 6)
            ->withQuantity('farine', 0.5)
            ->withPlannedRecipe('recipe-1')
            ->build());

        // When
        $this->client->request('GET', '/api/v1/shopping-lists/active/summary', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetActiveShoppingListSummaryQuery::class, $query);
        $this->assertSame($user->getId(), $query->ownerId);
        $this->assertSame([
            'shoppingListId' => 'list-active',
            'quantityByIngredientSlug' => ['oeuf' => 6, 'farine' => 0.5],
            'plannedRecipeIds' => ['recipe-1'],
        ], $this->jsonBody());
    }

    public function testWithoutActiveListReturnsAnEmptySummaryWithAnObjectForQuantities(): void
    {
        // Given
        $this->queryBus->willReturn(GetActiveShoppingListSummaryQuery::class, new ActiveShoppingListSummaryDTOBuilder()->withoutShoppingList()->build());

        // When
        $this->client->request('GET', '/api/v1/shopping-lists/active/summary', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(
            '{"shoppingListId":null,"quantityByIngredientSlug":{},"plannedRecipeIds":[]}',
            $this->client->getResponse()->getContent(),
        );
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-lists/active/summary');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
