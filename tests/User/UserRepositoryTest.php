<?php

namespace Repas\Tests\User;

use Repas\Tests\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Repository\UserPostgreSQLRepository;

class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = static::getContainer()->get(UserRepository::class);
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
        $actual = $this->userRepository->findOneByEmail($user->getEmail());
        RepasAssert::assertUser($user, $actual);

        //Act
        $user->setEmail('test2@test.com');
        $user->setDefaultServing(8);
        $this->userRepository->save($user);

        //Assert
        $actual = $this->userRepository->findOneByEmail($user->getEmail());
        RepasAssert::assertUser($user, $actual);
    }


    public function testUserNotFound(): void
    {
        //Assert
        $this->expectExceptionObject(UserException::NotFound('unknown@test.com'));

        //Act
        $this->userRepository->findOneByEmail('unknown@test.com');
    }
}
