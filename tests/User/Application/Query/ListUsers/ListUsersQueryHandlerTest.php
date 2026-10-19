<?php

namespace Repas\Tests\User\Application\Query\ListUsers;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryLoader\UserInMemoryLoader;
use Repas\User\Application\Query\ListUsers\ListUsersQuery;
use Repas\User\Application\Query\ListUsers\ListUsersQueryHandler;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;

class ListUsersQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $users = [new UserSummaryDTO('user-1', 'a@example.com', 4, 3, 2, 1, '2026-10-13T08:56:40+02:00', false, true, 'ACTIVE', '0.1.8', false)];
        $loader = new UserInMemoryLoader(users: $users);
        $handler = new ListUsersQueryHandler($loader);

        // When
        $result = $handler(new ListUsersQuery());

        // Then
        $this->assertSame($users, $result);
        $this->assertSame(1, $loader->listCalls);
    }
}
