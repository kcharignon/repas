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

        $admin = $this->savedUser(admin: true);

        // When
        $this->client->request('GET', '/api/v1/conversions', server: $this->bearerFor($admin));

        // Then : la question porte le lecteur, qui ne voit que ses conversions et les conversions générales
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(new ListConversionsQuery($admin->getId()), $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                [
                    'id' => 'conversion-1', 'ingredientSlug' => 'beurre', 'ingredientName' => 'beurre', 'creatorEmail' => 'createur@example.com',
                    'startUnitSlug' => 'plaquette', 'startUnitName' => 'plaquette', 'endUnitSlug' => 'gramme', 'endUnitName' => 'gramme', 'coefficient' => 250,
                    'departmentSlug' => 'epicerie', 'departmentName' => 'épicerie',
                ],
                [
                    'id' => 'conversion-2', 'ingredientSlug' => null, 'ingredientName' => null, 'creatorEmail' => null,
                    'startUnitSlug' => 'kilo', 'startUnitName' => 'kilo', 'endUnitSlug' => 'gramme', 'endUnitName' => 'gramme', 'coefficient' => 1000,
                    'departmentSlug' => null, 'departmentName' => null,
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
