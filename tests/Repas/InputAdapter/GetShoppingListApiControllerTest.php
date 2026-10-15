<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListMealDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRecipeTypeDTO;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DTO\ShoppingListDetailDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\ShoppingListRowDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetShoppingListApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheDetailOfTheList(): void
    {
        // Given
        $user = $this->savedUser();
        $shoppingList = $this->savedShoppingList($user);
        $this->queryBus->willReturn(GetShoppingListQuery::class, new ShoppingListDetailDTOBuilder()
            ->withId($shoppingList->getId())
            ->withName('Courses du week-end')
            ->withStatus(ShoppingListStatus::PAUSED)
            ->withVersion(7)
            ->withDepartment(new ShoppingListDepartmentDTO('divers', 'divers', 'images/divers.svg', [
                new ShoppingListRowDTOBuilder()->withId('row-egg')->withIngredientName('œuf')->withQuantity(6)->checked()->build(),
            ]))
            ->withRecipeType(new ShoppingListRecipeTypeDTO('plat', 'plat', 'images/plat.svg', [
                new ShoppingListMealDTO('meal-1', 'recipe-1', 'omelette', 4),
            ]))
            ->build());

        // When
        $this->client->request('GET', '/api/v1/shopping-lists/' . $shoppingList->getId(), server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('"7"', $this->client->getResponse()->headers->get('ETag'));
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetShoppingListQuery::class, $query);
        $this->assertSame($shoppingList->getId(), $query->shoppingListId);
        $this->assertSame([
            'id' => $shoppingList->getId(),
            'name' => 'Courses du week-end',
            'displayName' => 'Courses du week-end',
            'dateLabel' => '12 octobre 2026',
            'createdAt' => '2026-10-12T10:00:00+02:00',
            'status' => 'PAUSED',
            'isActive' => false,
            'isPaused' => true,
            'isCompleted' => false,
            'rowCount' => 1,
            'recipeCount' => 1,
            'departments' => [[
                'slug' => 'divers',
                'name' => 'divers',
                'image' => 'images/divers.svg',
                'rows' => [['id' => 'row-egg', 'ingredientName' => 'œuf', 'quantity' => 6, 'unitSymbol' => '', 'checked' => true]],
            ]],
            'recipeTypes' => [[
                'slug' => 'plat',
                'name' => 'plat',
                'image' => 'images/plat.svg',
                'meals' => [['id' => 'meal-1', 'recipeId' => 'recipe-1', 'recipeName' => 'omelette', 'serving' => 4]],
            ]],
            'version' => 7,
        ], $this->jsonBody());
    }

    public function testRefusesAListOfAnotherUser(): void
    {
        // Given
        $shoppingList = $this->savedShoppingList($this->savedUser());

        // When
        $this->client->request('GET', '/api/v1/shopping-lists/' . $shoppingList->getId(), server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testReturns404ForAnUnknownList(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-lists/unknown-list', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/shopping-lists/any-id');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
