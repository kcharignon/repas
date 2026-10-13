<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminGetAllDepartmentViewControllerTest extends QueryBusWebTestCase
{
    public function testPageRendersOneRowPerDepartmentFromTheDTOs(): void
    {
        // Given
        $this->queryBus->willReturn(ListDepartmentsQuery::class, [
            new DepartmentDTOBuilder()->withSlug('boulangerie')->withName('boulangerie')->build(),
            new DepartmentDTOBuilder()->withSlug('fromage')->withName('fromage')->build(),
        ]);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/department');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListDepartmentsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame('Boulangerie', trim($crawler->filter('#department-boulangerie td')->first()->text()));
        $this->assertSame('/admin/department/fromage/update', $crawler->filter('#department-fromage a')->attr('href'));
    }

    public function testNonAdminUserIsForbidden(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/department');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
