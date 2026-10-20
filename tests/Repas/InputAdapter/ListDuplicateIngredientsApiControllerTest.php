<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientSideDTO;
use Repas\Repas\Application\Query\ListDuplicateIngredients\ListDuplicateIngredientsQuery;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListDuplicateIngredientsApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItemsWithAllTheKeys(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListDuplicateIngredientsQuery::class, [
            new DuplicateIngredientDTO(
                new DuplicateIngredientSideDTO('tomate', 'Tomate', 'fruit-et-legume', 'Fruit & légume', 'kilo', 12, 3),
                new DuplicateIngredientSideDTO('tomates', 'Tomates', 'fruit-et-legume', 'Fruit & légume', 'kilo', 7, 2),
                1.0,
                'EXACT',
                'tomate',
            ),
            new DuplicateIngredientDTO(
                new DuplicateIngredientSideDTO('mozzarella', 'Mozzarella', 'frais', 'Frais', 'boule', 0, 0),
                new DuplicateIngredientSideDTO('mozzerella', 'Mozzerella', 'frais', 'Frais', 'boule', 0, 1),
                0.9381,
                'SUGGESTION',
                'mozzerella',
            ),
        ]);

        // When
        $this->client->request('GET', '/api/v1/ingredients/duplicates', server: $this->bearerFor($user));

        // Then : « duplicates » n'est pas lu comme le slug d'un ingrédient (GET /ingredients/{slug})
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListDuplicateIngredientsQuery($user->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                [
                    'first' => ['slug' => 'tomate', 'name' => 'Tomate', 'departmentSlug' => 'fruit-et-legume', 'departmentName' => 'Fruit & légume', 'purchaseUnitName' => 'kilo', 'recipeCount' => 12, 'listCount' => 3],
                    'second' => ['slug' => 'tomates', 'name' => 'Tomates', 'departmentSlug' => 'fruit-et-legume', 'departmentName' => 'Fruit & légume', 'purchaseUnitName' => 'kilo', 'recipeCount' => 7, 'listCount' => 2],
                    'score' => 1, // le JSON écrit 1 pour 1.0 : un nombre, pour le front
                    'level' => 'EXACT',
                    'suggestedKeep' => 'tomate',
                ],
                [
                    'first' => ['slug' => 'mozzarella', 'name' => 'Mozzarella', 'departmentSlug' => 'frais', 'departmentName' => 'Frais', 'purchaseUnitName' => 'boule', 'recipeCount' => 0, 'listCount' => 0],
                    'second' => ['slug' => 'mozzerella', 'name' => 'Mozzerella', 'departmentSlug' => 'frais', 'departmentName' => 'Frais', 'purchaseUnitName' => 'boule', 'recipeCount' => 0, 'listCount' => 1],
                    'score' => 0.9381,
                    'level' => 'SUGGESTION',
                    'suggestedKeep' => 'mozzerella',
                ],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testNoDuplicateIsAnEmptyListNotAnError(): void
    {
        // Given : un compte sans ingrédient personnel, un administrateur compris
        $this->queryBus->willReturn(ListDuplicateIngredientsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/ingredients/duplicates', server: $this->bearerFor($this->savedUser(true)));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testTheUserIsTheOneOfTheTokenWhateverTheQueryString(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListDuplicateIngredientsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/ingredients/duplicates?userId=quelquun-d-autre&user=quelquun-d-autre', server: $this->bearerFor($user));

        // Then
        $this->assertEquals(new ListDuplicateIngredientsQuery($user->getId()), $this->queryBus->getLastAskedQuery());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/duplicates');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
