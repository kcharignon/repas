<?php

namespace Repas\Tests\User;


use Repas\Tests\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Repository\UserPostgreSQLRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userRepository = new UserPostgreSQLRepository($this->entityManager);
    }


    public function testInsertUser(): void
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
    }
}
