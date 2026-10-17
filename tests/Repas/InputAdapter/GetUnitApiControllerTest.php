<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetUnit\GetUnitQuery;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetUnitApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheItem(): void
    {
        // Given
        $this->queryBus->willReturn(GetUnitQuery::class, new UnitDTOBuilder()->withSlug('zzz')->withName('zzz pincée')->withSymbol('zz')->build());

        // When
        $this->client->request('GET', '/api/v1/units/zzz', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetUnitQuery::class, $query);
        $this->assertSame('zzz', $query->slug);
        $this->assertSame(['slug' => 'zzz', 'name' => 'zzz pincée', 'symbol' => 'zz', 'dimension' => null, 'baseFactor' => null], $this->jsonBody());
    }

    public function testReturnsTheDimensionAndTheBaseFactorOfAMeasuredUnit(): void
    {
        // Given
        $this->queryBus->willReturn(GetUnitQuery::class, new UnitDTOBuilder()->withSlug('kilo')->withName('kilo')->withSymbol('kg')->withMeasure('WEIGHT', 1000.0)->build());

        // When
        $this->client->request('GET', '/api/v1/units/kilo', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['slug' => 'kilo', 'name' => 'kilo', 'symbol' => 'kg', 'dimension' => 'WEIGHT', 'baseFactor' => 1000], $this->jsonBody());
    }

    public function testReturns404ForAnUnknownItem(): void
    {
        // Given
        $this->queryBus->willThrow(GetUnitQuery::class, UnitException::notFound('zzz'));

        // When
        $this->client->request('GET', '/api/v1/units/zzz', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/units/zzz');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
