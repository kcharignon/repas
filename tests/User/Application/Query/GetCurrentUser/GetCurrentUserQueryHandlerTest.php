<?php

namespace Repas\Tests\User\Application\Query\GetCurrentUser;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\DTO\CurrentUserDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\UserInMemoryLoader;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQuery;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQueryHandler;
use Repas\User\Domain\Exception\UserException;

class GetCurrentUserQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsForTheRequestedUser(): void
    {
        // Given
        $user = new CurrentUserDTOBuilder()->withId('user-42')->build();
        $loader = new UserInMemoryLoader($user);
        $handler = new GetCurrentUserQueryHandler($loader);

        // When
        $result = $handler(new GetCurrentUserQuery('user-42'));

        // Then
        $this->assertSame($user, $result);
        $this->assertSame(['user-42'], $loader->getCalls);
    }

    public function testLetsTheUnknownUserExceptionOfTheLoaderGoThrough(): void
    {
        // Given
        $handler = new GetCurrentUserQueryHandler(new UserInMemoryLoader());

        // Then
        $this->expectExceptionObject(UserException::NotFound('ghost'));

        // When
        $handler(new GetCurrentUserQuery('ghost'));
    }
}
