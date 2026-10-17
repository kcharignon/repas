<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListUnits\ListUnitsQuery;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListUnitsApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItems(): void
    {
        // Given
        $this->queryBus->willReturn(ListUnitsQuery::class, [
            new UnitDTOBuilder()->withSlug('gramme')->withName('gramme')->withSymbol('g')->withMeasure('WEIGHT', 1.0)->build(),
            new UnitDTOBuilder()->withSlug('unite')->withName('unité')->withSymbol('')->build(),
        ]);

        // When
        $this->client->request('GET', '/api/v1/units', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListUnitsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                ['slug' => 'gramme', 'name' => 'gramme', 'symbol' => 'g', 'dimension' => 'WEIGHT', 'baseFactor' => 1],
                ['slug' => 'unite', 'name' => 'unité', 'symbol' => '', 'dimension' => null, 'baseFactor' => null],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testListReturnsAnEmptyCollection(): void
    {
        // Given
        $this->queryBus->willReturn(ListUnitsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/units', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testListWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/units');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
