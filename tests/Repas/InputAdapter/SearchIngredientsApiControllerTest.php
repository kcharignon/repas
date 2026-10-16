<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Application\Query\SearchIngredients\SearchIngredientsQuery;
use Repas\Tests\Helper\QueryBusWebTestCase;

class SearchIngredientsApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheResultsInTheOrderOfTheQueryWithTheirDepartment(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(SearchIngredientsQuery::class, [
            new IngredientSearchResultDTO('tomate', 'tomate', 'g', false, 'fruits-et-legumes', 'Fruits et légumes', 'images/department/fruits.png'),
            new IngredientSearchResultDTO('sauce-tomate', 'sauce tomate', '', true, 'epicerie', 'Épicerie', 'images/department/epicerie.png'),
        ]);

        // When
        $this->client->request('GET', '/api/v1/ingredients/search?q=tomtae', server: $this->bearerFor($user));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new SearchIngredientsQuery('tomtae', $user->getId(), false), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                [
                    'slug' => 'tomate', 'name' => 'tomate', 'purchaseUnitSymbol' => 'g', 'canEdit' => false,
                    'departmentSlug' => 'fruits-et-legumes', 'departmentName' => 'Fruits et légumes', 'departmentImage' => 'images/department/fruits.png',
                ],
                [
                    'slug' => 'sauce-tomate', 'name' => 'sauce tomate', 'purchaseUnitSymbol' => '', 'canEdit' => true,
                    'departmentSlug' => 'epicerie', 'departmentName' => 'Épicerie', 'departmentImage' => 'images/department/epicerie.png',
                ],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testAnAdministratorIsAskedAsSuch(): void
    {
        // Given
        $this->queryBus->willReturn(SearchIngredientsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/ingredients/search?q=tom', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertTrue($this->queryBus->getAskedQuery(SearchIngredientsQuery::class)->viewerIsAdmin);
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testTheSearchRouteIsNotTakenForAnIngredientSlug(): void
    {
        // Given
        $this->queryBus->willReturn(SearchIngredientsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/ingredients/search?q=tom', server: $this->bearerFor($this->savedUser()));

        // Then : sinon `GET /api/v1/ingredients/{slug}` répondrait 404 « ingrédient search inconnu »
        $this->assertSame(1, $this->queryBus->countAskedQueries());
        $this->assertInstanceOf(SearchIngredientsQuery::class, $this->queryBus->getLastAskedQuery());
    }

    public function testASearchThatIsMissingBlankOrTooLongIsRefused(): void
    {
        // Given
        $server = $this->bearerFor($this->savedUser());

        foreach (['', '?q=', '?q=%20%20', '?q=' . str_repeat('a', 101)] as $queryString) {
            // When
            $this->client->request('GET', '/api/v1/ingredients/search' . $queryString, server: $server);

            // Then
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $queryString);
            $this->assertArrayHasKey('error', $this->jsonBody());
        }
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/search?q=tom');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
