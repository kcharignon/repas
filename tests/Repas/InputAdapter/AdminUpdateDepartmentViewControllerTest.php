<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetDepartment\GetDepartmentQuery;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminUpdateDepartmentViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsPrefilledFromTheDepartmentDTO(): void
    {
        // Given
        $this->queryBus->willReturn(GetDepartmentQuery::class, new DepartmentDTOBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/department/zzz-outils/update');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetDepartmentQuery::class, $query);
        $this->assertSame('zzz-outils', $query->slug);
        $this->assertSame('Zzz Outils', $crawler->filter('input[name="update_department[name]"]')->attr('value'));
        $this->assertSame('file://images/zzz.jpg', $crawler->filter('input[name="update_department[image]"]')->attr('value'));
    }

    public function testValidSubmissionUpdatesTheDepartmentThenRedirectsToTheList(): void
    {
        // Given
        $repository = static::getContainer()->get(DepartmentRepository::class);
        $repository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build());
        $this->queryBus->willReturn(GetDepartmentQuery::class, new DepartmentDTOBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $this->client->request('POST', '/admin/department/zzz-outils/update', [
            'update_department' => ['name' => 'Zzz Bricolage', 'image' => 'file://images/zzz2.jpg', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/admin/department'));
        $this->assertSame('Zzz Bricolage', $repository->findOneBySlug('zzz-outils')->getName());
    }

    public function testNonAdminUserIsForbidden(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/department/zzz-outils/update');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
