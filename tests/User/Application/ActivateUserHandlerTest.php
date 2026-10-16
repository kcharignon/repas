<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\ActivateUser\ActivateUserCommand;
use Repas\User\Application\ActivateUser\ActivateUserHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\UserStatus;

class ActivateUserHandlerTest extends TestCase
{
    private UserInMemoryRepository $userRepository;
    private EmailVerificationTokenInMemoryRepository $tokenRepository;
    private ActivateUserHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository();
        $this->tokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->handler = new ActivateUserHandler($this->userRepository, $this->tokenRepository);
    }

    public function testAPendingAccountBecomesActiveAndItsLinkNoLongerWorks(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'user-id', EmailVerificationToken::hash('the-secret'), new DateTimeImmutable('2026-10-16 12:00:00')));

        // When
        ($this->handler)(new ActivateUserCommand('user-id'));

        // Then
        $this->assertSame(UserStatus::ACTIVE, $this->userRepository->findOneById('user-id')->getStatus());
        $this->assertSame([], $this->tokenRepository->all());
    }

    public function testAnActiveAccountIsAConflict(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());

        // Assert
        $this->expectExceptionObject(UserException::invalidStatusTransition(UserStatus::ACTIVE, UserStatus::ACTIVE));

        // When
        ($this->handler)(new ActivateUserCommand('user-id'));
    }

    public function testADisabledAccountIsNotReactivated(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isDisabled()->build());

        // When
        try {
            ($this->handler)(new ActivateUserCommand('user-id'));
            $this->fail('Un compte désactivé ne repasse pas par `activate`.');
        } catch (UserException $exception) {
            // Then
            $this->assertSame(409, $exception->getCode());
            $this->assertSame(UserStatus::DISABLED, $this->userRepository->findOneById('user-id')->getStatus());
        }
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        // Assert
        $this->expectExceptionObject(UserException::NotFound('ghost-id'));

        // When
        ($this->handler)(new ActivateUserCommand('ghost-id'));
    }
}
