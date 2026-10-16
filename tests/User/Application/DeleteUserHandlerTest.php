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
        ($this->handler)(new DeleteUserCommand('user-id', 'admin-id'));

        // Then
        $this->assertFalse($this->userRepository->emailExists("user-id@example.com"));
        $this->assertTrue($this->userRepository->emailExists("other-id@example.com"));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNull($this->emailVerificationTokenRepository->findOneByUserId('user-id'));
        $this->assertEquals([new UserDeletedEvent('user-id')], $this->eventDispatcher->eventDispatched);
    }

    public function testAnAdministratorCannotDeleteHisOwnAccount(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('admin-id')->isAdmin()->build());

        // When
        try {
            ($this->handler)(new DeleteUserCommand('admin-id', 'admin-id'));
            $this->fail('Un administrateur ne supprime pas son propre compte.');
        } catch (UserException $exception) {
            // Then
            $this->assertSame(UserException::CANNOT_MANAGE_OWN_ACCOUNT, $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
            $this->assertTrue($this->userRepository->emailExists('admin-id@example.com'));
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
