<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Event\UserActivatedEvent;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DummyPasswordHasher;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\PasswordResetTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\ResetPassword\ResetPasswordCommand;
use Repas\User\Application\ResetPassword\ResetPasswordHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;

class ResetPasswordHandlerTest extends TestCase
{
    private UserInMemoryRepository $userRepository;
    private PasswordResetTokenInMemoryRepository $tokenRepository;
    private RefreshTokenInMemoryRepository $refreshTokenRepository;
    private EmailVerificationTokenInMemoryRepository $emailVerificationTokenRepository;
    private DateTimeImmutable $now;
    private SpyEventDispatcher $eventDispatcher;
    private ResetPasswordHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-19 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->tokenRepository = new PasswordResetTokenInMemoryRepository();
        $this->refreshTokenRepository = new RefreshTokenInMemoryRepository();
        $this->emailVerificationTokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new ResetPasswordHandler(
            $this->tokenRepository,
            $this->userRepository,
            $this->refreshTokenRepository,
            $this->emailVerificationTokenRepository,
            new DummyPasswordHasher(),
            new FrozenClock($this->now),
            $this->eventDispatcher,
        );
    }

    public function testAPendingAccountThatTheLinkActivatesAnnouncesItsActivation(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());
        $this->issueLink('user-id');

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then : l'activation est annoncée une fois, avec l'identifiant du compte
        $this->assertEquals([new UserActivatedEvent('user-id')], $this->eventDispatcher->eventDispatched);
    }

    public function testAnActiveAccountResettingItsPasswordAnnouncesNothing(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->issueLink('user-id');

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then : un compte déjà actif qui le reste n'émet rien (et ne reçoit donc rien de plus)
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
    }

    public function testADisabledAccountIsRefusedAndAnnouncesNothing(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isDisabled()->build());
        $this->issueLink('user-id');

        // When
        try {
            ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));
            $this->fail('Un compte désactivé ne change rien.');
        } catch (UserException) {
            // Then
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testTheLinkFixesTheNewPasswordAndIsConsumed(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withPassword('hasholdhash')->build());
        $this->issueLink('user-id', minutesAgo: 10);

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then : le mot de passe change, les identifiants déjà émis sont révoqués, le lien ne sert plus
        $user = $this->userRepository->findOneById('user-id');
        $this->assertSame('hasha new passwordhash', $user->getPassword());
        $this->assertSame(1, $user->getCredentialsVersion());
        $this->assertSame(UserStatus::ACTIVE, $user->getStatus());
        $this->assertSame([], $this->tokenRepository->all());
    }

    public function testEveryConnectionOfTheAccountIsRevokedAndOthersAreKept(): void
    {
        // Given : deux appareils connectés, et un autre compte
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->issueLink('user-id');
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-2', 'family-2', 'user-id', 'hash-2', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-3', 'family-3', 'other-id', 'hash-3', 0, $this->now));

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-3'));
    }

    public function testThePasswordIsFixedWhateverTheCurrentOneIs(): void
    {
        // Given : un compte sans mot de passe utilisable (créé par une connexion Google)
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withPassword('!')->build());
        $this->issueLink('user-id');

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a first password'));

        // Then
        $this->assertSame('hasha first passwordhash', $this->userRepository->findOneById('user-id')->getPassword());
    }

    public function testAPendingAccountIsConfirmedAndActivatedAndItsConfirmationLinkIsDropped(): void
    {
        // Given : l'adresse a été inscrite par un tiers, le lien reçu prouve qu'elle est à celui qui le lit
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('verif-id', 'user-id', 'verif-hash', $this->now));
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('verif-other-id', 'other-id', 'verif-other-hash', $this->now));
        $this->issueLink('user-id');

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then
        $this->assertSame(UserStatus::ACTIVE, $this->userRepository->findOneById('user-id')->getStatus());
        $this->assertNull($this->emailVerificationTokenRepository->findOneByUserId('user-id'));
        $this->assertNotNull($this->emailVerificationTokenRepository->findOneByUserId('other-id'));
    }

    public function testAnActiveAccountKeepsItsConfirmationLinkUntouched(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('verif-id', 'user-id', 'verif-hash', $this->now));
        $this->issueLink('user-id');

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then
        $this->assertNotNull($this->emailVerificationTokenRepository->findOneByUserId('user-id'));
    }

    public function testTheProtectedAccountCanResetItsOwnPassword(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail(User::PROTECTED_EMAIL)->isAdmin()->build());
        $this->issueLink('user-id');

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then
        $this->assertSame('hasha new passwordhash', $this->userRepository->findOneById('user-id')->getPassword());
    }

    public function testTheLinkCannotBeUsedTwice(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->issueLink('user-id');
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Assert
        $this->expectExceptionObject(UserException::invalidPasswordResetToken());

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'another password'));
    }

    public function testAnUnknownLinkIsRefused(): void
    {
        // Assert
        $this->expectExceptionObject(UserException::invalidPasswordResetToken());

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('unknown'), 'a new password'));
    }

    public function testAnExpiredLinkIsRefusedAndNothingChanges(): void
    {
        // Given : un lien émis il y a plus de 30 minutes
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withPassword('hasholdhash')->isPending()->build());
        $this->issueLink('user-id', minutesAgo: 31);

        // When
        try {
            ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));
            $this->fail('Un lien expiré doit être refusé.');
        } catch (UserException $exception) {
            // Then : même erreur qu'un lien inconnu, le compte est intact
            $this->assertSame(UserException::INVALID_PASSWORD_RESET_TOKEN, $exception->getMessage());
            $user = $this->userRepository->findOneById('user-id');
            $this->assertSame(['hasholdhash', UserStatus::PENDING, 0], [$user->getPassword(), $user->getStatus(), $user->getCredentialsVersion()]);
        }
    }

    public function testALinkJustBeforeItsExpiryStillWorks(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->tokenRepository->add(PasswordResetToken::issue('token-id', 'user-id', PasswordResetToken::hash('the-secret'), $this->now->modify('-29 minutes -59 seconds')));

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));

        // Then
        $this->assertSame('hasha new passwordhash', $this->userRepository->findOneById('user-id')->getPassword());
    }

    public function testALinkWhoseAccountNoLongerExistsIsRefused(): void
    {
        // Given
        $this->issueLink('ghost-user-id');

        // Assert
        $this->expectExceptionObject(UserException::invalidPasswordResetToken());

        // When
        ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));
    }

    public function testADisabledAccountIsRefusedLikeAnInvalidLinkAndNothingIsWritten(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withPassword('hasholdhash')->isDisabled()->build());
        $this->issueLink('user-id');
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));

        // When
        try {
            ($this->handler)(new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'a new password'));
            $this->fail('Un compte désactivé ne se réinitialise pas.');
        } catch (UserException $exception) {
            // Then : même erreur, le compte reste désactivé avec son mot de passe, et ses jetons ne sont pas touchés
            $this->assertSame(UserException::INVALID_PASSWORD_RESET_TOKEN, $exception->getMessage());
            $user = $this->userRepository->findOneById('user-id');
            $this->assertSame(['hasholdhash', UserStatus::DISABLED, 0], [$user->getPassword(), $user->getStatus(), $user->getCredentialsVersion()]);
            $this->assertCount(1, $this->tokenRepository->all());
            $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        }
    }

    private function issueLink(string $userId, int $minutesAgo = 0): void
    {
        $this->tokenRepository->add(PasswordResetToken::issue('token-id', $userId, PasswordResetToken::hash('the-secret'), $this->now->modify("-{$minutesAgo} minutes")));
    }
}
