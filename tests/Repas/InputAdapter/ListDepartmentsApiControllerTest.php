<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class ListDepartmentsApiControllerTest extends QueryBusWebTestCase
{
    public function testListReturnsMembersAndTotalItems(): void
    {
        // Given
        $this->queryBus->willReturn(ListDepartmentsQuery::class, [
            new DepartmentDTOBuilder()->withSlug('boulangerie')->withName('Boulangerie')->withImage('images/boulangerie.png')->build(),
            new DepartmentDTOBuilder()->withSlug('fromage')->withName('Fromage')->withImage('images/fromage.png')->build(),
        ]);

        // When
        $this->client->request('GET', '/api/v1/departments', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListDepartmentsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame([
            'member' => [
                ['slug' => 'boulangerie', 'name' => 'Boulangerie', 'image' => 'images/boulangerie.png'],
                ['slug' => 'fromage', 'name' => 'Fromage', 'image' => 'images/fromage.png'],
            ],
            'totalItems' => 2,
        ], $this->jsonBody());
    }

    public function testListReturnsAnEmptyCollection(): void
    {
        // Given
        $this->queryBus->willReturn(ListDepartmentsQuery::class, []);

        // When
        $this->client->request('GET', '/api/v1/departments', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testListWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/departments');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
