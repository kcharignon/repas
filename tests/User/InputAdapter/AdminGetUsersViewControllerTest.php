<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\Query\ListUsers\ListUsersQuery;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;

class AdminGetUsersViewControllerTest extends QueryBusWebTestCase
{
    public function testPageListsTheUsersFromTheDTOs(): void
    {
        // Given
        $this->queryBus->willReturn(ListUsersQuery::class, [
            new UserSummaryDTO('user-1', 'admin@example.com', 5, 3, 2, 4, '2026-10-13T08:56:40+02:00', false, true),
            new UserSummaryDTO('user-2', 'inactif@example.com', 2, 0, 0, 0, '', true, false),
        ]);
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('GET', '/admin/user');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertInstanceOf(ListUsersQuery::class, $this->queryBus->getLastAskedQuery());
        $cells = static fn(string $id): array => array_map('trim', $crawler->filter("#user-$id td")->extract(['_text']));
        $this->assertSame(['admin@example.com', '5', '3', '2', '4', '2026-10-13', 'actif', 'Administrateur'], $cells('user-1'));
        $this->assertSame(['inactif@example.com', '2', '0', '0', '0', '', 'inactif', 'Utilisateur'], $cells('user-2'));
    }

    public function testRefusesANonAdministrator(): void
    {
        // Given
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/admin/user');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
