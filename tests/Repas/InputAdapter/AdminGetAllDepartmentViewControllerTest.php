<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\MockQueryBus;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class AdminGetAllDepartmentViewControllerTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private MockQueryBus $queryBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queryBus = new MockQueryBus();
        static::getContainer()->set(QueryBusInterface::class, $this->queryBus);
        $this->client = static::getContainer()->get('test.client');
    }

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
        $this->client->loginUser($this->savedUser(admin: false));

        // When
        $this->client->request('GET', '/admin/department');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    private function savedUser(bool $admin): User
    {
        $builder = new UserBuilder()->withEmail($admin ? 'department-admin@example.com' : 'department-user@example.com');
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
