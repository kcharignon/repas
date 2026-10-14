<?php

namespace Repas\Tests\User;

use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;

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


    public function testEmailExistsOnlyForASavedEmail(): void
    {
        //Arrange
        $this->userRepository->save(new UserBuilder()->withEmail('known@test.com')->build());

        //Act & Assert
        $this->assertTrue($this->userRepository->emailExists('known@test.com'));
        $this->assertFalse($this->userRepository->emailExists('unknown@test.com'));
    }

    public function testTheEmailIsFoundWhateverItsCase(): void
    {
        //Arrange
        $user = new UserBuilder()->withEmail('known@test.com')->build();
        $this->userRepository->save($user);

        //Act & Assert
        $this->assertTrue($this->userRepository->emailExists('Known@Test.COM'));
        $this->assertSame($user->getId(), $this->userRepository->findOneByEmail('  KNOWN@test.com ')->getId());
    }

    public function testTheSameEmailSavedTwiceGivesAnEmailAlreadyUsedError(): void
    {
        //Arrange : deux inscriptions simultanées ont passé le contrôle d'existence
        $this->userRepository->save(new UserBuilder()->withEmail('twice@test.com')->build());

        //Assert
        $this->expectExceptionObject(UserException::emailAlreadyUsed('twice@test.com'));

        //Act
        $this->userRepository->save(new UserBuilder()->withEmail('twice@test.com')->build());
    }

    public function testUserNotFound(): void
    {
        //Assert
        $this->expectExceptionObject(UserException::NotFound('unknown@test.com'));

        //Act
        $this->userRepository->findOneByEmail('unknown@test.com');
    }
}
