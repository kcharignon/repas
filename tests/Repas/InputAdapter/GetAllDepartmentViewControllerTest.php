<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class GetAllDepartmentViewControllerTest extends QueryBusWebTestCase
{
    public function testPageRendersOneCardPerDepartmentFromTheDTOs(): void
    {
        // Given
        $this->queryBus->willReturn(ListDepartmentsQuery::class, [
            new DepartmentDTOBuilder()->withSlug('boulangerie')->withName('boulangerie')->withImage('images/boulangerie.png')->build(),
            new DepartmentDTOBuilder()->withSlug('fromage')->withName('fromage')->withImage('images/fromage.png')->build(),
        ]);
        $this->client->loginUser($this->savedUser());

        // When
        $crawler = $this->client->request('GET', '/department');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListDepartmentsQuery::class, $this->queryBus->getLastAskedQuery());
        $this->assertSame(['Boulangerie', 'Fromage', 'Nouvel ingredient'], array_map('trim', $crawler->filter('.card-header')->extract(['_text'])));
        $this->assertSame('/department/boulangerie', $crawler->filter('.card a')->eq(0)->attr('href'));
        $this->assertStringEndsWith('images/fromage.png', $crawler->filter('.card img')->eq(1)->attr('src'));
    }

    public function testAnonymousUserIsRedirectedToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/department');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
