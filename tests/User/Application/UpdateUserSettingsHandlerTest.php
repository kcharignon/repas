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
                ->withPassword('hashpasswordhash')
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
            ->withPassword('hashpasswordhash')
            ->withServing(1)
            ->withCreatedAt($this->clock->now())
            ->build();
        $actual = $this->userRepository->findOneByEmail('john.doe@example.com');
        RepasAssert::assertUser($expected, $actual);
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
