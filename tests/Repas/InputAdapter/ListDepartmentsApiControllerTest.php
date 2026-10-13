<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\MockQueryBus;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class ListDepartmentsApiControllerTest extends DatabaseTestCase
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

    public function testListReturnsMembersAndTotalItems(): void
    {
        // Given
        $this->queryBus->willReturn(ListDepartmentsQuery::class, [
            new DepartmentDTOBuilder()->withSlug('boulangerie')->withName('Boulangerie')->withImage('images/boulangerie.png')->build(),
            new DepartmentDTOBuilder()->withSlug('fromage')->withName('Fromage')->withImage('images/fromage.png')->build(),
        ]);

        // When
        $this->client->request('GET', '/api/v1/departments', server: $this->authenticatedUser());

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
        $this->client->request('GET', '/api/v1/departments', server: $this->authenticatedUser());

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

    /**
     * @return array<string, string>
     */
    private function authenticatedUser(): array
    {
        $user = new UserBuilder()->withEmail('api-user@example.com')->build();
        static::getContainer()->get(UserRepository::class)->save($user);
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
