<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Repas\User\Application\DeleteUser\DeleteUserHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Model\User;

class DeleteUserHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private UserInMemoryRepository $userRepository;
    private RefreshTokenInMemoryRepository $refreshTokenRepository;
    private EmailVerificationTokenInMemoryRepository $emailVerificationTokenRepository;
    private SpyEventDispatcher $eventDispatcher;
    private DeleteUserHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->refreshTokenRepository = new RefreshTokenInMemoryRepository();
        $this->emailVerificationTokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new DeleteUserHandler($this->userRepository, $this->refreshTokenRepository, $this->emailVerificationTokenRepository, $this->eventDispatcher);
    }

    public function testTheAccountItsConnectionsAndItsLinksAreDeletedAndTheDeletionIsAnnounced(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->build());
        $this->userRepository->save(new UserBuilder()->withId('other-id')->build());
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-2', 'family-2', 'other-id', 'hash-2', 0, $this->now));
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('link-1', 'user-id', 'link-hash-1', $this->now));

        // When
        ($this->handler)(new DeleteUserCommand('user-id'));

        // Then
        $this->assertFalse($this->userRepository->emailExists("user-id@example.com"));
        $this->assertTrue($this->userRepository->emailExists("other-id@example.com"));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNull($this->emailVerificationTokenRepository->findOneByUserId('user-id'));
        $this->assertEquals([new UserDeletedEvent('user-id')], $this->eventDispatcher->eventDispatched);
    }

    public function testAnAdministratorCanDeleteHisOwnAccount(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('admin-id')->isAdmin()->build());

        // When
        ($this->handler)(new DeleteUserCommand('admin-id'));

        // Then
        $this->assertFalse($this->userRepository->emailExists('admin-id@example.com'));
        $this->assertEquals([new UserDeletedEvent('admin-id')], $this->eventDispatcher->eventDispatched);
    }

    public function testTheProtectedAccountIsNeitherDeletedNorAnnounced(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('owner-id')->withEmail(User::PROTECTED_EMAIL)->isAdmin()->build());
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'owner-id', 'hash-1', 0, $this->now));

        // When
        try {
            ($this->handler)(new DeleteUserCommand('owner-id'));
            $this->fail('Le compte protégé ne se supprime pas.');
        } catch (UserException $exception) {
            // Then
            $this->assertEquals(UserException::protectedAccount(), $exception);
            $this->assertTrue($this->userRepository->emailExists(User::PROTECTED_EMAIL));
            $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testAnUnknownAccountIsNotFoundAndNothingIsAnnounced(): void
    {
        // When
        try {
            ($this->handler)(new DeleteUserCommand('ghost-id'));
            $this->fail('Un compte inconnu est introuvable.');
        } catch (UserException $exception) {
            // Then
            $this->assertSame(404, $exception->getCode());
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }
}
