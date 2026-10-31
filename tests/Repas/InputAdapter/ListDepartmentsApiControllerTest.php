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
            new DepartmentDTOBuilder()->withSlug('boulangerie')->withName('Boulangerie')->withImage('images/boulangerie.png')->withIcon('snowflake')->withListTypeId('type-1')->buildListItem(),
            new DepartmentDTOBuilder()->withSlug('fromage')->withName('Fromage')->withImage('images/fromage.png')->buildListItem(),
        ]);

        // When
        $user = $this->savedUser();
        $this->client->request('GET', '/api/v1/departments', server: $this->bearerFor($user));

        // Then : la query porte l'utilisateur du jeton (le type de liste d'un rayon est propre à son compte)
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(ListDepartmentsQuery::class, $query);
        $this->assertSame($user->getId(), $query->userId);
        $this->assertSame([
            'member' => [
                ['slug' => 'boulangerie', 'name' => 'Boulangerie', 'image' => 'images/boulangerie.png', 'icon' => 'snowflake', 'listTypeId' => 'type-1'],
                ['slug' => 'fromage', 'name' => 'Fromage', 'image' => 'images/fromage.png', 'icon' => null, 'listTypeId' => null],
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
