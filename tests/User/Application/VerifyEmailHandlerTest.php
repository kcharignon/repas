<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Shared\Domain\Event\UserActivatedEvent;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\VerifyEmail\VerifyEmailCommand;
use Repas\User\Application\VerifyEmail\VerifyEmailHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\UserStatus;

class VerifyEmailHandlerTest extends TestCase
{
    private UserInMemoryRepository $userRepository;
    private EmailVerificationTokenInMemoryRepository $tokenRepository;
    private DateTimeImmutable $now;
    private SpyEventDispatcher $eventDispatcher;
    private VerifyEmailHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->tokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new VerifyEmailHandler($this->tokenRepository, $this->userRepository, new FrozenClock($this->now), $this->eventDispatcher);
    }

    public function testTheActivationIsAnnouncedOnceAndOnlyAfterTheAccountIsSavedAndTheLinkConsumed(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'user-id', EmailVerificationToken::hash('the-secret'), $this->now));

        // When
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));

        // Then : un événement, qui porte l'identifiant du compte
        $this->assertEquals([new UserActivatedEvent('user-id')], $this->eventDispatcher->eventDispatched);
    }

    public function testAnAlreadyActiveAccountConfirmingAgainAnnouncesNothing(): void
    {
        // Given : un compte déjà actif qui a encore un lien de confirmation (renvoyé avant la confirmation par un autre chemin)
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'user-id', EmailVerificationToken::hash('the-secret'), $this->now));

        // When
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));

        // Then
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
        $this->assertSame(UserStatus::ACTIVE, $this->userRepository->findOneById('user-id')->getStatus());
    }

    public function testAnInvalidLinkAnnouncesNothing(): void
    {
        // When
        try {
            ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('unknown')));
            $this->fail('Un lien inconnu doit être refusé.');
        } catch (UserException) {
            // Then
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testTheLinkActivatesThePendingAccountAndIsConsumed(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'user-id', EmailVerificationToken::hash('the-secret'), $this->now->modify('-1 hour')));

        // When
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));

        // Then
        $this->assertSame(UserStatus::ACTIVE, $this->userRepository->findOneById('user-id')->getStatus());
        $this->assertSame([], $this->tokenRepository->all());
    }

    public function testTheLinkCannotBeUsedTwice(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'user-id', EmailVerificationToken::hash('the-secret'), $this->now));
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));

        // Assert
        $this->expectExceptionObject(UserException::invalidEmailVerificationToken());

        // When
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));
    }

    public function testAnUnknownLinkIsRefused(): void
    {
        // Assert
        $this->expectExceptionObject(UserException::invalidEmailVerificationToken());

        // When
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('unknown')));
    }

    public function testAnExpiredLinkIsRefusedAndTheAccountStaysPending(): void
    {
        // Given : un lien émis il y a plus de 24 heures
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isPending()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'user-id', EmailVerificationToken::hash('the-secret'), $this->now->modify('-25 hours')));

        // When
        try {
            ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));
            $this->fail('Un lien expiré doit être refusé.');
        } catch (UserException $exception) {
            // Then
            $this->assertSame(UserException::INVALID_EMAIL_VERIFICATION_TOKEN, $exception->getMessage());
            $this->assertSame(UserStatus::PENDING, $this->userRepository->findOneById('user-id')->getStatus());
        }
    }

    public function testALinkWhoseAccountNoLongerExistsIsRefused(): void
    {
        // Given
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'ghost-user-id', EmailVerificationToken::hash('the-secret'), $this->now));

        // Assert
        $this->expectExceptionObject(UserException::invalidEmailVerificationToken());

        // When
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));
    }

    public function testConfirmingNeverReactivatesADisabledAccount(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->isDisabled()->build());
        $this->tokenRepository->add(EmailVerificationToken::issue('token-id', 'user-id', EmailVerificationToken::hash('the-secret'), $this->now));

        // When
        ($this->handler)(new VerifyEmailCommand(EmailVerificationToken::hash('the-secret')));

        // Then : le compte reste désactivé et rien n'est annoncé (aucun amorçage d'un compte qui n'est pas actif)
        $this->assertSame(UserStatus::DISABLED, $this->userRepository->findOneById('user-id')->getStatus());
        $this->assertSame([], $this->eventDispatcher->eventDispatched);
    }
}
