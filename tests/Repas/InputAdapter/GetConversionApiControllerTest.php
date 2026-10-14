<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetConversion\GetConversionQuery;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Tests\Helper\Builder\DTO\ConversionDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetConversionApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorGetsTheConversion(): void
    {
        // Given
        $this->queryBus->willReturn(GetConversionQuery::class, new ConversionDTOBuilder()->withId('conversion-1')->build());

        // When
        $this->client->request('GET', '/api/v1/conversions/conversion-1', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetConversionQuery::class, $query);
        $this->assertSame('conversion-1', $query->conversionId);
        $this->assertSame(
            ['id', 'ingredientSlug', 'ingredientName', 'creatorEmail', 'startUnitSlug', 'startUnitName', 'endUnitSlug', 'endUnitName', 'coefficient'],
            array_keys($this->jsonBody()),
        );
        $this->assertSame('conversion-1', $this->jsonBody()['id']);
    }

    public function testRefusesAUserWhoIsNotAnAdministrator(): void
    {
        // When
        $this->client->request('GET', '/api/v1/conversions/conversion-1', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testReturns404ForAnUnknownConversion(): void
    {
        // Given
        $this->queryBus->willThrow(GetConversionQuery::class, ConversionException::notFound('unknown'));

        // When
        $this->client->request('GET', '/api/v1/conversions/unknown', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // When
        $this->client->request('GET', '/api/v1/conversions/conversion-1');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
