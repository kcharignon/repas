<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientsToFixDTO;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientToFixDTO;
use Repas\Repas\Application\Query\ListIngredientsToFix\ListIngredientsToFixQuery;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListIngredientsToFixApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItemsWithAllTheKeys(): void
    {
        // Given : deux ingrédients rendus, sur trois à régler (le plafond ne rend pas tout, `totalItems` dit le vrai nombre)
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListIngredientsToFixQuery::class, new IngredientsToFixDTO(
            [
                new IngredientToFixDTO('biscotte', 'biscotte', 'Épicerie', 'unite', 'unité', 'paquet', 'paquet'),
                new IngredientToFixDTO('huile-ab12', 'huile', 'Épicerie', 'cuillere-a-soupe', 'cuillère à soupe', 'bouteille', 'bouteille'),
            ],
            3,
        ));

        // When
        $this->client->request('GET', '/api/v1/ingredients/to-fix', server: $this->bearerFor($user));

        // Then : « to-fix » n'est pas lu comme le slug d'un ingrédient (GET /ingredients/{slug})
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListIngredientsToFixQuery($user->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                ['slug' => 'biscotte', 'name' => 'biscotte', 'departmentName' => 'Épicerie', 'cookingUnitSlug' => 'unite', 'cookingUnitName' => 'unité', 'purchaseUnitSlug' => 'paquet', 'purchaseUnitName' => 'paquet'],
                ['slug' => 'huile-ab12', 'name' => 'huile', 'departmentName' => 'Épicerie', 'cookingUnitSlug' => 'cuillere-a-soupe', 'cookingUnitName' => 'cuillère à soupe', 'purchaseUnitSlug' => 'bouteille', 'purchaseUnitName' => 'bouteille'],
            ],
            'totalItems' => 3,
        ], $this->jsonBody());
    }

    public function testNothingToFixIsAnEmptyListNotAnError(): void
    {
        // Given
        $this->queryBus->willReturn(ListIngredientsToFixQuery::class, new IngredientsToFixDTO([], 0));

        // When
        $this->client->request('GET', '/api/v1/ingredients/to-fix', server: $this->bearerFor($this->savedUser(true)));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testTheUserIsTheOneOfTheTokenWhateverTheQueryString(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListIngredientsToFixQuery::class, new IngredientsToFixDTO([], 0));

        // When
        $this->client->request('GET', '/api/v1/ingredients/to-fix?userId=quelquun-d-autre&user=quelquun-d-autre', server: $this->bearerFor($user));

        // Then
        $this->assertEquals(new ListIngredientsToFixQuery($user->getId()), $this->queryBus->getLastAskedQuery());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/to-fix');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
