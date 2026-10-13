<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\ListCompatibleUnitsQuery;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListCompatibleUnitsApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsMembersAndTotalItems(): void
    {
        // Given
        $this->queryBus->willReturn(ListCompatibleUnitsQuery::class, [new CompatibleUnitDTO('gramme', 'Gramme'), new CompatibleUnitDTO('kilo', 'Kilo')]);

        // When
        $this->client->request('GET', '/api/v1/ingredients/farine/units', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListCompatibleUnitsQuery::class, $query);
        $this->assertSame('farine', $query->ingredientSlug);
        $this->assertSame([
            'member' => [['slug' => 'gramme', 'name' => 'Gramme'], ['slug' => 'kilo', 'name' => 'Kilo']],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testReturns404ForAnUnknownIngredient(): void
    {
        // Given
        $this->queryBus->willThrow(ListCompatibleUnitsQuery::class, IngredientException::notFound('inconnu'));

        // When
        $this->client->request('GET', '/api/v1/ingredients/inconnu/units', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/ingredients/farine/units');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
