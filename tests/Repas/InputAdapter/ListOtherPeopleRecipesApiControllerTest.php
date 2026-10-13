<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListOtherPeopleRecipesApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsMembersAndTotalItems(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, [
            new OtherPeopleRecipeDTO('recipe-1', 'pâtes carbonara', 'plat'),
            new OtherPeopleRecipeDTO('recipe-2', 'tarte au citron', 'dessert'),
        ]);

        // When
        $this->client->request('GET', '/api/v1/recipes/other-people', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListOtherPeopleRecipesQuery::class, $query);
        $this->assertSame($user->getId(), $query->userId);
        $this->assertSame([
            'member' => [
                ['id' => 'recipe-1', 'name' => 'pâtes carbonara', 'typeName' => 'plat'],
                ['id' => 'recipe-2', 'name' => 'tarte au citron', 'typeName' => 'dessert'],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testReturnsAnEmptyCollection(): void
    {
        // Given
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/recipes/other-people', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/other-people');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
