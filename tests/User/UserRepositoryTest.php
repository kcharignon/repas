<?php

namespace Repas\Tests\User;


use Repas\Tests\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Repository\UserPostgreSQLRepository;

class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $managerRegistry = static::getContainer()->get('doctrine');

        $this->userRepository = new UserPostgreSQLRepository($managerRegistry);
    }


    public function testInsertAndUpdateUser(): void
    {
        //Arrange
        $user = new UserBuilder()
            ->withEmail('test@test.com')
            ->build();

        //Act
        $this->userRepository->save($user);
        $this->entityManager->flush();

        //Assert
        $actual = $this->userRepository->getUserByEmail($user->getEmail());
        $this->assertEqualsCanonicalizing($user, $actual);

        //Act
        $user->setEmail('test2@test.com');
        $this->userRepository->save($user);

        //Assert
        $actual = $this->userRepository->getUserByEmail($user->getEmail());
        $this->assertEqualsCanonicalizing($user, $actual);
    }


    public function testUserNotFound(): void
    {
        //Assert
        $this->expectExceptionObject(UserException::NotFound());

        //Act
        $this->userRepository->getUserByEmail('unknown@test.com');
    }
}
