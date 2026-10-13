<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetDepartment\GetDepartmentQuery;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetDepartmentApiControllerTest extends QueryBusWebTestCase
{
    public function testReturnsTheItem(): void
    {
        // Given
        $this->queryBus->willReturn(GetDepartmentQuery::class, new DepartmentDTOBuilder()->withSlug('zzz')->withName('zzz outils')->withImage('images/zzz.svg')->build());

        // When
        $this->client->request('GET', '/api/v1/departments/zzz', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetDepartmentQuery::class, $query);
        $this->assertSame('zzz', $query->slug);
        $this->assertSame(['slug' => 'zzz', 'name' => 'zzz outils', 'image' => 'images/zzz.svg'], $this->jsonBody());
    }

    public function testReturns404ForAnUnknownItem(): void
    {
        // Given
        $this->queryBus->willThrow(GetDepartmentQuery::class, DepartmentException::notFound());

        // When
        $this->client->request('GET', '/api/v1/departments/zzz', server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/departments/zzz');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
