<?php

namespace Repas\Tests\Repas\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListOtherPeopleRecipesApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsMembersAndTotalItems(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, new OtherPeopleRecipesDTO([
            new OtherPeopleRecipeDTO('recipe-1', 'pâtes carbonara', 'plat'),
            new OtherPeopleRecipeDTO('recipe-2', 'tarte au citron', 'dessert'),
        ], 2));

        // When
        $this->client->request('GET', '/api/v1/recipes/other-people', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListOtherPeopleRecipesQuery::class, $query);
        $this->assertSame([$user->getId(), 200, 0], [$query->userId, $query->limit, $query->offset]);
        $this->assertSame([
            'member' => [
                ['id' => 'recipe-1', 'name' => 'pâtes carbonara', 'typeName' => 'plat'],
                ['id' => 'recipe-2', 'name' => 'tarte au citron', 'typeName' => 'dessert'],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testTotalItemsIsTheRealTotalEvenWhenThePageIsShorter(): void
    {
        // Given
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, new OtherPeopleRecipesDTO([new OtherPeopleRecipeDTO('recipe-1', 'omelette', 'plat')], 1234));

        // When
        $this->client->request('GET', '/api/v1/recipes/other-people', server: $this->bearerFor($this->savedUser()));

        // Then
        $body = $this->jsonBody();
        $this->assertCount(1, $body['member']);
        $this->assertSame(1234, $body['totalItems']);
    }

    public function testTransmitsThePageAskedFor(): void
    {
        // Given
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, new OtherPeopleRecipesDTO([], 0));

        // When
        $this->client->request('GET', '/api/v1/recipes/other-people?limit=50&offset=100', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListOtherPeopleRecipesQuery::class, $query);
        $this->assertSame([50, 100], [$query->limit, $query->offset]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPages(): iterable
    {
        yield 'zéro recette' => ['limit=0'];
        yield 'plus de deux cents' => ['limit=201'];
        yield 'négatif' => ['limit=-1'];
        yield 'pas un nombre' => ['limit=beaucoup'];
        yield 'décalage négatif' => ['offset=-1'];
        yield 'décalage démesuré' => ['offset=99999999'];
        yield 'décalage pas un nombre' => ['offset=abc'];
    }

    #[DataProvider('invalidPages')]
    public function testRefusesAPageOutsideItsBounds(string $queryString): void
    {
        // When
        $this->client->request('GET', '/api/v1/recipes/other-people?' . $queryString, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testReturnsAnEmptyCollection(): void
    {
        // Given
        $this->queryBus->willReturn(ListOtherPeopleRecipesQuery::class, new OtherPeopleRecipesDTO([], 0));

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
