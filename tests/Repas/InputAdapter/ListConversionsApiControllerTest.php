<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListConversions\ListConversionsQuery;
use Repas\Tests\Helper\Builder\DTO\ConversionDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListConversionsApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAdministratorGetsMembersAndTotalItems(): void
    {
        // Given
        $this->queryBus->willReturn(ListConversionsQuery::class, [
            new ConversionDTOBuilder()->withId('conversion-1')->withIngredient('beurre', 'beurre', 'createur@example.com')->withUnits('plaquette', 'plaquette', 'gramme', 'gramme')->withCoefficient(250)->build(),
            new ConversionDTOBuilder()->withId('conversion-2')->withIngredient(null, null)->withUnits('kilo', 'kilo', 'gramme', 'gramme')->withCoefficient(1000)->build(),
        ]);

        // When
        $this->client->request('GET', '/api/v1/conversions', server: $this->bearerFor($this->savedUser(admin: true)));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListConversionsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                [
                    'id' => 'conversion-1', 'ingredientSlug' => 'beurre', 'ingredientName' => 'beurre', 'creatorEmail' => 'createur@example.com',
                    'startUnitSlug' => 'plaquette', 'startUnitName' => 'plaquette', 'endUnitSlug' => 'gramme', 'endUnitName' => 'gramme', 'coefficient' => 250,
                ],
                [
                    'id' => 'conversion-2', 'ingredientSlug' => null, 'ingredientName' => null, 'creatorEmail' => null,
                    'startUnitSlug' => 'kilo', 'startUnitName' => 'kilo', 'endUnitSlug' => 'gramme', 'endUnitName' => 'gramme', 'coefficient' => 1000,
                ],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testAUserWithoutTheAdminRoleIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/conversions', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/conversions');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
