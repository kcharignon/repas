<?php

namespace Repas\Tests\User\OutputAdapter;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;

class UserPostgreSQLLoaderTest extends DatabaseTestCase
{
    private UserRepository $userRepository;
    private UserLoader $userLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = static::getContainer()->get(UserRepository::class);
        $this->userLoader = static::getContainer()->get(UserLoader::class);
    }

    public function testGetReturnsTheDTOOfTheRequestedUser(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->withEmail('first@example.com')->withServing(2)->build());
        $this->userRepository->save(new UserBuilder()->withId('22222222-2222-4222-8222-222222222222')->withEmail('second@example.com')->withServing(6)->build());

        // When
        $result = $this->userLoader->get('22222222-2222-4222-8222-222222222222');

        // Then
        $this->assertEquals(
            new CurrentUserDTO('22222222-2222-4222-8222-222222222222', 'second@example.com', ['ROLE_USER'], false, 6),
            $result,
        );
    }

    public function testGetFlagsAnAdministrator(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('33333333-3333-4333-8333-333333333333')->withEmail('admin@example.com')->isAdmin()->build());

        // When
        $result = $this->userLoader->get('33333333-3333-4333-8333-333333333333');

        // Then
        $this->assertTrue($result->isAdmin);
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $result->roles);
    }

    public function testGetDoesNotExposeTheCredentials(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('44444444-4444-4444-8444-444444444444')->build());

        // When
        $result = $this->userLoader->get('44444444-4444-4444-8444-444444444444');

        // Then
        $this->assertSame(['id', 'email', 'roles', 'isAdmin', 'defaultServing'], array_keys(get_object_vars($result)));
    }

    public function testGetThrowsWhenTheUserDoesNotExist(): void
    {
        // Then
        $this->expectExceptionObject(UserException::NotFound('99999999-9999-4999-8999-999999999999'));

        // When
        $this->userLoader->get('99999999-9999-4999-8999-999999999999');
    }
}
