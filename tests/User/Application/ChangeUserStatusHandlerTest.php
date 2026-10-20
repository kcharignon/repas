<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Event\UserActivatedEvent;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\EmailVerificationTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\ChangeUserStatus\ChangeUserStatusCommand;
use Repas\User\Application\ChangeUserStatus\ChangeUserStatusHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;

class ChangeUserStatusHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private UserInMemoryRepository $userRepository;
    private RefreshTokenInMemoryRepository $refreshTokenRepository;
    private EmailVerificationTokenInMemoryRepository $emailVerificationTokenRepository;
    private SpyEventDispatcher $eventDispatcher;
    private ChangeUserStatusHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
        $this->userRepository = new UserInMemoryRepository();
        $this->refreshTokenRepository = new RefreshTokenInMemoryRepository();
        $this->emailVerificationTokenRepository = new EmailVerificationTokenInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();
        $this->handler = new ChangeUserStatusHandler($this->userRepository, $this->refreshTokenRepository, $this->emailVerificationTokenRepository, $this->eventDispatcher);
    }

    #[DataProvider('everyChangeOfStatus')]
    public function testTheActivationIsAnnouncedExactlyWhenTheAccountBecomesActive(UserStatus $from, UserStatus $to): void
    {
        // Given
        $this->userRepository->save($this->userWithStatus($from));

        // When
        ($this->handler)(new ChangeUserStatusCommand('user-id', $to));

        // Then : un compte qui devient actif l'annonce ; un compte actif qui le reste, ou qui ne l'est pas, n'annonce rien
        $becomesActive = $to === UserStatus::ACTIVE && $from !== UserStatus::ACTIVE;
        $this->assertEquals($becomesActive ? [new UserActivatedEvent('user-id')] : [], $this->eventDispatcher->eventDispatched);
    }

    public function testNothingIsAnnouncedWhenTheProtectedAccountIsRefused(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail(User::PROTECTED_EMAIL)->build());

        // When
        try {
            ($this->handler)(new ChangeUserStatusCommand('user-id', UserStatus::DISABLED));
            $this->fail('Le compte protégé est intouchable.');
        } catch (UserException) {
            // Then
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    #[DataProvider('everyChangeOfStatus')]
    public function testTheStatusChangesFromAnyStatusToAnyOther(UserStatus $from, UserStatus $to): void
    {
        // Given
        $this->userRepository->save($this->userWithStatus($from));

        // When
        ($this->handler)(new ChangeUserStatusCommand('user-id', $to));

        // Then
        $this->assertSame($to, $this->userRepository->findOneById('user-id')->getStatus());
    }

    /**
     * @return iterable<string, array{UserStatus, UserStatus}>
     */
    public static function everyChangeOfStatus(): iterable
    {
        foreach (UserStatus::cases() as $from) {
            foreach (UserStatus::cases() as $to) {
                yield "$from->value vers $to->value" => [$from, $to];
            }
        }
    }

    #[DataProvider('statusesThatCloseTheConnections')]
    public function testAnAccountThatIsNoLongerActiveLosesEveryConnectionButNotTheOthers(UserStatus $to): void
    {
        // Given : deux appareils connectés, plus la connexion d'un autre compte
        $this->userRepository->save($this->userWithStatus(UserStatus::ACTIVE));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-2', 'family-2', 'user-id', 'hash-2', 0, $this->now));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-3', 'family-3', 'other-id', 'hash-3', 0, $this->now));

        // When
        ($this->handler)(new ChangeUserStatusCommand('user-id', $to));

        // Then
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-3'));
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function statusesThatCloseTheConnections(): iterable
    {
        yield 'désactivé' => [UserStatus::DISABLED];
        yield 'en attente' => [UserStatus::PENDING];
    }

    public function testAnActiveAccountKeepsItsConnections(): void
    {
        // Given
        $this->userRepository->save($this->userWithStatus(UserStatus::DISABLED));
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));

        // When
        ($this->handler)(new ChangeUserStatusCommand('user-id', UserStatus::ACTIVE));

        // Then
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
    }

    #[DataProvider('statusesThatEndTheConfirmation')]
    public function testTheConfirmationLinkIsRevokedUnlessTheAccountMustStillConfirm(UserStatus $to, bool $linkKept): void
    {
        // Given
        $this->userRepository->save($this->userWithStatus(UserStatus::PENDING));
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('link-1', 'user-id', 'link-hash-1', $this->now));
        $this->emailVerificationTokenRepository->add(EmailVerificationToken::issue('link-2', 'other-id', 'link-hash-2', $this->now));

        // When
        ($this->handler)(new ChangeUserStatusCommand('user-id', $to));

        // Then
        $this->assertSame($linkKept, $this->emailVerificationTokenRepository->findOneByUserId('user-id') !== null);
        $this->assertNotNull($this->emailVerificationTokenRepository->findOneByUserId('other-id'));
    }

    /**
     * @return iterable<string, array{UserStatus, bool}>
     */
    public static function statusesThatEndTheConfirmation(): iterable
    {
        yield 'actif' => [UserStatus::ACTIVE, false];
        yield 'désactivé' => [UserStatus::DISABLED, false];
        yield 'toujours en attente' => [UserStatus::PENDING, true];
    }

    #[DataProvider('statusesRefusedToTheProtectedAccount')]
    public function testTheProtectedAccountIsUntouchableAndKeepsItsConnections(UserStatus $to): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail(User::PROTECTED_EMAIL)->build());
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-1', 'family-1', 'user-id', 'hash-1', 0, $this->now));

        // When
        try {
            ($this->handler)(new ChangeUserStatusCommand('user-id', $to));
            $this->fail('Le compte protégé est intouchable.');
        } catch (UserException $exception) {
            // Then
            $this->assertEquals(UserException::protectedAccount(), $exception);
            $this->assertSame(UserStatus::ACTIVE, $this->userRepository->findOneById('user-id')->getStatus());
            $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        }
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function statusesRefusedToTheProtectedAccount(): iterable
    {
        yield 'désactivé' => [UserStatus::DISABLED];
        yield 'en attente' => [UserStatus::PENDING];
    }

    public function testTheProtectedAccountStaysActiveWhenActiveIsRequested(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-id')->withEmail(User::PROTECTED_EMAIL)->build());

        // When
        ($this->handler)(new ChangeUserStatusCommand('user-id', UserStatus::ACTIVE));

        // Then
        $this->assertSame(UserStatus::ACTIVE, $this->userRepository->findOneById('user-id')->getStatus());
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        // Assert
        $this->expectExceptionObject(UserException::NotFound('ghost-id'));

        // When
        ($this->handler)(new ChangeUserStatusCommand('ghost-id', UserStatus::ACTIVE));
    }

    private function userWithStatus(UserStatus $status): User
    {
        $builder = new UserBuilder()->withId('user-id');
        $builder = match ($status) {
            UserStatus::PENDING => $builder->isPending(),
            UserStatus::DISABLED => $builder->isDisabled(),
            UserStatus::ACTIVE => $builder,
        };

        return $builder->build();
    }
}
