<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Tests\Helper\Builder\UserAppVersionBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\Builder\UserIdentityBuilder;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\PasswordResetTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UserAppVersionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserIdentityInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Repas\User\Application\DeleteUser\DeleteUserHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserAppVersion;

class DeleteUserHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private UserInMemoryRepository $userRepository;
    private RefreshTokenInMemoryRepository $refreshTokenRepository;
    private EmailVerificationTokenInMemoryRepository $emailVerificationTokenRepository;
    private PasswordResetTokenInMemoryRepository $passwordResetTokenRepository;
    private UserIdentityInMemoryRepository $userIdentityRepository;
    private UserAppVersionInMemoryRepository $userAppVersionRepository;
    private SpyEventDispatcher $eventDispatcher;
    private DeleteUserHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->refreshTokenRepository = new RefreshTokenInMemoryRepository();
        $this->emailVerificationTokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->passwordResetTokenRepository = new PasswordResetTokenInMemoryRepository();
        $this->userIdentityRepository = new UserIdentityInMemoryRepository();
        $this->userAppVersionRepository = new UserAppVersionInMemoryRepository($this->userRepository);
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new DeleteUserHandler($this->userRepository, $this->refreshTokenRepository, $this->emailVerificationTokenRepository, $this->passwordResetTokenRepository, $this->userIdentityRepository, $this->userAppVersionRepository, $this->eventDispatcher);
    }

    public function testTheAccountItsConnectionsItsLinksAndItsAppVersionsAreDeletedAndTheDeletionIsAnnounced(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->userRepository->save(new UserBuilder()->withId('other-id')->build());
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-2', 'family-2', 'other-id', 'hash-2', 0, $this->now));
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('link-1', 'user-id', 'link-hash-1', $this->now));
        $this->passwordResetTokenRepository->add(PasswordResetToken::issue('reset-1', 'user-id', 'reset-hash-1', $this->now));
        $this->passwordResetTokenRepository->add(PasswordResetToken::issue('reset-2', 'other-id', 'reset-hash-2', $this->now));
        $this->userIdentityRepository->save(new UserIdentityBuilder()->withUserId('user-id')->withSubject('sujet-test-1')->build());
        $this->userIdentityRepository->save(new UserIdentityBuilder()->withUserId('other-id')->withSubject('sujet-test-2')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('user-id')->withVersion('0.1.8')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('other-id')->withVersion('0.1.10')->build());

        // When
        ($this->handler)(new DeleteUserCommand('user-id', 'admin-id'));

        // Then
        $this->assertFalse($this->userRepository->emailExists("user-id@example.com"));
        $this->assertTrue($this->userRepository->emailExists("other-id@example.com"));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNull($this->emailVerificationTokenRepository->findOneByUserId('user-id'));
        $this->assertNull($this->passwordResetTokenRepository->findOneByUserId('user-id'));
        $this->assertNotNull($this->passwordResetTokenRepository->findOneByUserId('other-id'));
        $this->assertNull($this->userIdentityRepository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-1'));
        $this->assertNull($this->userIdentityRepository->findOneByUserAndProvider('user-id', IdentityProvider::GOOGLE));
        $this->assertSame('other-id', $this->userIdentityRepository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-2')?->getUserId());
        $this->assertSame(['other-id'], array_map(static fn(UserAppVersion $appVersion): string => $appVersion->getUserId(), $this->userAppVersionRepository->all()));
        $this->assertEquals([new UserDeletedEvent('user-id')], $this->eventDispatcher->eventDispatched);
    }

    public function testAnAdministratorCannotDeleteHisOwnAccount(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('admin-id')->isAdmin()->build());
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'admin-id', 'hash-1', 0, $this->now));
        $this->userIdentityRepository->save(new UserIdentityBuilder()->withUserId('admin-id')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('admin-id')->build());

        // When
        try {
            ($this->handler)(new DeleteUserCommand('admin-id', 'admin-id'));
            $this->fail('Un administrateur ne supprime pas son propre compte.');
        } catch (UserException $exception) {
            // Then
            $this->assertEquals(UserException::cannotDeleteOwnAccount(), $exception);
            $this->assertSame(422, $exception->getCode());
            $this->assertTrue($this->userRepository->emailExists('admin-id@example.com'));
            $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
            $this->assertNotNull($this->userIdentityRepository->findOneByUserAndProvider('admin-id', IdentityProvider::GOOGLE));
            $this->assertCount(1, $this->userAppVersionRepository->all());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testAnAdministratorCanDeleteAnotherAdministrator(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('other-admin-id')->isAdmin()->build());

        // When
        ($this->handler)(new DeleteUserCommand('other-admin-id', 'admin-id'));

        // Then
        $this->assertFalse($this->userRepository->emailExists('other-admin-id@example.com'));
        $this->assertEquals([new UserDeletedEvent('other-admin-id')], $this->eventDispatcher->eventDispatched);
    }

    public function testTheProtectedAccountIsNeitherDeletedNorAnnounced(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('owner-id')->withEmail(User::PROTECTED_EMAIL)->isAdmin()->build());
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'owner-id', 'hash-1', 0, $this->now));
        $this->userIdentityRepository->save(new UserIdentityBuilder()->withUserId('owner-id')->build());
        $this->userAppVersionRepository->save(new UserAppVersionBuilder()->withUserId('owner-id')->build());

        // When
        try {
            ($this->handler)(new DeleteUserCommand('owner-id', 'admin-id'));
            $this->fail('Le compte protégé ne se supprime pas.');
        } catch (UserException $exception) {
            // Then
            $this->assertEquals(UserException::protectedAccount(), $exception);
            $this->assertTrue($this->userRepository->emailExists(User::PROTECTED_EMAIL));
            $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
            $this->assertNotNull($this->userIdentityRepository->findOneByUserAndProvider('owner-id', IdentityProvider::GOOGLE));
            $this->assertCount(1, $this->userAppVersionRepository->all());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testAnUnknownAccountIsNotFoundAndNothingIsAnnounced(): void
    {
        // When
        try {
            ($this->handler)(new DeleteUserCommand('ghost-id', 'admin-id'));
            $this->fail('Un compte inconnu est introuvable.');
        } catch (UserException $exception) {
            // Then
            $this->assertSame(404, $exception->getCode());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }
}
