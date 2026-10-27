<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionsDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\ListIngredientConversionsQuery;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListIngredientConversionsApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheOwnAndTheUniversalConversionsOfTheIngredient(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(ListIngredientConversionsQuery::class, new IngredientConversionsDTO(
            member: [new IngredientConversionDTO('own-1', 'cuillere-a-soupe', 'cuillère à soupe', 'gramme', 'gramme', 10.0)],
            common: [new IngredientConversionDTO('universal-1', 'kilo', 'kilo', 'gramme', 'gramme', 1000.0)],
            totalItems: 1,
            convertible: true,
            cookingUnitSlug: 'cuillere-a-soupe',
            purchaseUnitSlug: 'kilo',
        ));

        // When
        $this->client->request('GET', '/api/v1/ingredients/farine/conversions', server: $this->bearerFor($user));

        // Then : la requête porte l'utilisateur du jeton, le contrat a ces clés
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListIngredientConversionsQuery::class, $query);
        $this->assertSame(['farine', $user->getId()], [$query->slug, $query->viewerId]);
        $this->assertSame([
            'member' => [[
                'id' => 'own-1',
                'startUnitSlug' => 'cuillere-a-soupe',
                'startUnitName' => 'cuillère à soupe',
                'endUnitSlug' => 'gramme',
                'endUnitName' => 'gramme',
                'coefficient' => 10,
            ]],
            'common' => [[
                'id' => 'universal-1',
                'startUnitSlug' => 'kilo',
                'startUnitName' => 'kilo',
                'endUnitSlug' => 'gramme',
                'endUnitName' => 'gramme',
                'coefficient' => 1000,
            ]],
            'totalItems' => 1,
            'convertible' => true,
            'cookingUnitSlug' => 'cuillere-a-soupe',
            'purchaseUnitSlug' => 'kilo',
        ], $this->jsonBody());
    }

    public function testReturns404ForAnIngredientOfAnotherUser(): void
    {
        // Given : le loader ne connaît pas l'ingrédient d'un autre, un administrateur compris
        $this->queryBus->willThrow(ListIngredientConversionsQuery::class, IngredientException::notFound('farine'));

        // When
        $this->client->request('GET', '/api/v1/ingredients/farine/conversions', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/farine/conversions');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
