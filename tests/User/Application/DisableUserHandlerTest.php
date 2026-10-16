<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\DisableUser\DisableUserCommand;
use Repas\User\Application\DisableUser\DisableUserHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Model\UserStatus;

class DisableUserHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private UserInMemoryRepository $userRepository;
    private RefreshTokenInMemoryRepository $refreshTokenRepository;
    private EmailVerificationTokenInMemoryRepository $emailVerificationTokenRepository;
    private DisableUserHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->refreshTokenRepository = new RefreshTokenInMemoryRepository();
        $this->emailVerificationTokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->handler = new DisableUserHandler($this->userRepository, $this->refreshTokenRepository, $this->emailVerificationTokenRepository);
    }

    #[DataProvider('disableableAccounts')]
    public function testAnActiveOrPendingAccountIsDisabled(UserBuilder $builder): void
    {
        // Given
        $this->userRepository->save($builder->withId('user-id')->build());

        // When
        ($this->handler)(new DisableUserCommand('user-id', 'admin-id'));

        // Then
        $this->assertSame(UserStatus::DISABLED, $this->userRepository->findOneById('user-id')->getStatus());
    }

    /**
     * @return iterable<string, array{UserBuilder}>
     */
    public static function disableableAccounts(): iterable
    {
        yield 'actif' => [new UserBuilder()];
        yield 'en attente' => [new UserBuilder()->isPending()];
    }

    public function testEveryConnectionAndEveryLinkOfTheAccountIsRevokedButNotTheOthers(): void
    {
        // Given : deux appareils connectés et un lien de confirmation, plus les jetons d'un autre compte
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-2', 'family-2', 'user-id', 'hash-2', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-3', 'family-3', 'other-id', 'hash-3', 0, $this->now));
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('link-1', 'user-id', 'link-hash-1', $this->now));
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('link-2', 'other-id', 'link-hash-2', $this->now));

        // When
        ($this->handler)(new DisableUserCommand('user-id', 'admin-id'));

        // Then
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-3'));
        $this->assertNull($this->emailVerificationTokenRepository->findOneByUserId('user-id'));
        $this->assertNotNull($this->emailVerificationTokenRepository->findOneByUserId('other-id'));
    }

    public function testAnAdministratorCannotDisableHisOwnAccount(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('admin-id')->isAdmin()->build());

        // When
        try {
            ($this->handler)(new DisableUserCommand('admin-id', 'admin-id'));
            $this->fail('Un administrateur ne désactive pas son propre compte.');
        } catch (UserException $exception) {
            // Then
            $this->assertSame(UserException::CANNOT_MANAGE_OWN_ACCOUNT, $exception->getMessage());
            $this->assertSame(UserStatus::ACTIVE, $this->userRepository->findOneById('admin-id')->getStatus());
        }
    }

    public function testADisabledAccountIsAConflict(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isDisabled()->build());

        // Assert
        $this->expectExceptionObject(UserException::invalidStatusTransition(UserStatus::DISABLED, UserStatus::DISABLED));

        // When
        ($this->handler)(new DisableUserCommand('user-id', 'admin-id'));
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        // Assert
        $this->expectExceptionObject(UserException::NotFound('ghost-id'));

        // When
        ($this->handler)(new DisableUserCommand('ghost-id', 'admin-id'));
    }
}
