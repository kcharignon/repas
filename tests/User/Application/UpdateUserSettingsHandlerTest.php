<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DummyPasswordHasher;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsCommand;
use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class UpdateUserSettingsHandlerTest extends TestCase
{
    private readonly UpdateUserSettingsHandler $handler;
    private readonly UserRepository $userRepository;
    private readonly PasswordHasherFactoryInterface $passwordHasherFactory;
    private readonly ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->passwordHasherFactory = new DummyPasswordHasher();
        $this->userRepository = new UserInMemoryRepository([
            new UserBuilder()
                ->withId('unique-id')
                ->withEmail('john.doe@example.com')
                ->withPassword(password_hash('current-password', PASSWORD_BCRYPT, ['cost' => 4]))
                ->withServing(15)
                ->withCreatedAt($this->clock->now())
                ->build(),
        ]);
        $this->handler = new UpdateUserSettingsHandler(
            $this->userRepository,
            $this->passwordHasherFactory,
        );
    }

    public function testSuccessfullyHandleUpdateUserSettingsWithNewPassword(): void
    {
        // Arrange
        $command = new UpdateUserSettingsCommand(
            'unique-id',
            'new password',
            1,
            'current-password',
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new UserBuilder()
            ->withId('unique-id')
            ->withEmail('john.doe@example.com')
            ->withPassword('hashnew passwordhash')
            ->withServing(1)
            ->withCreatedAt($this->clock->now())
            ->build();
        $actual = $this->userRepository->findOneByEmail('john.doe@example.com');
        RepasAssert::assertUser($expected, $actual);
    }


    public function testSuccessfullyHandleUpdateUserSettingsPasswordDontChange(): void
    {
        // Arrange
        $command = new UpdateUserSettingsCommand(
            'unique-id',
            null,
            1,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new UserBuilder()
            ->withId('unique-id')
            ->withEmail('john.doe@example.com')
            ->withPassword($this->userRepository->findOneById('unique-id')->getPassword())
            ->withServing(1)
            ->withCreatedAt($this->clock->now())
            ->build();
        $actual = $this->userRepository->findOneByEmail('john.doe@example.com');
        RepasAssert::assertUser($expected, $actual);
    }

    public function testRefusesANewPasswordWithoutTheCurrentOne(): void
    {
        // Arrange
        $before = $this->userRepository->findOneById('unique-id')->getPassword();

        // Assert
        $this->expectExceptionObject(UserException::invalidPassword());

        // Act
        try {
            ($this->handler)(new UpdateUserSettingsCommand('unique-id', 'new password', 1));
        } finally {
            $user = $this->userRepository->findOneById('unique-id');
            $this->assertSame([$before, 15], [$user->getPassword(), $user->getDefaultServing()]);
        }
    }

    public function testRefusesANewPasswordWithAWrongCurrentPassword(): void
    {
        // Assert
        $this->expectExceptionObject(UserException::invalidPassword());

        // Act
        ($this->handler)(new UpdateUserSettingsCommand('unique-id', 'new password', 1, 'wrong-password'));
    }

    public function testDoesNotAskForTheCurrentPasswordToChangeTheServingOnly(): void
    {
        // Act
        ($this->handler)(new UpdateUserSettingsCommand('unique-id', null, 3));

        // Assert
        $this->assertSame(3, $this->userRepository->findOneById('unique-id')->getDefaultServing());
    }

    public function testChangingThePasswordRevokesTheTokensButNotChangingItDoesNot(): void
    {
        // Act : le nombre de personnes seul ne touche pas aux identifiants
        ($this->handler)(new UpdateUserSettingsCommand('unique-id', null, 2));
        $this->assertSame(0, $this->userRepository->findOneById('unique-id')->getCredentialsVersion());

        // Act : un vrai changement de mot de passe augmente la version (jetons déjà émis révoqués)
        ($this->handler)(new UpdateUserSettingsCommand('unique-id', 'new password', 2, 'current-password'));
        $this->assertSame(1, $this->userRepository->findOneById('unique-id')->getCredentialsVersion());
    }

    public function testFailedHandleUpdateUserSettingsUnkownId(): void
    {
        // Arrange
        $command = new UpdateUserSettingsCommand(
            'not-found',
            null,
            1,
        );

        // Assert
        $this->expectExceptionObject(UserException::NotFound('not-found'));

        // Act
        ($this->handler)($command);
    }
}
