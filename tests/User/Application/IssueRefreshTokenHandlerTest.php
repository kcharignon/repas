<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\IssueRefreshToken\IssueRefreshTokenCommand;
use Repas\User\Application\IssueRefreshToken\IssueRefreshTokenHandler;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\RefreshToken;

class IssueRefreshTokenHandlerTest extends TestCase
{
    private RefreshTokenInMemoryRepository $refreshTokenRepository;
    private IssueRefreshTokenHandler $handler;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->refreshTokenRepository = new RefreshTokenInMemoryRepository();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-14 12:00:00'));
        $user = new UserBuilder()->withId('user-id')->build();
        $user->changePassword('nouveau-hash');
        $this->handler = new IssueRefreshTokenHandler(
            $this->refreshTokenRepository,
            new UserInMemoryRepository([$user]),
            new UuidGenerator(),
            $this->clock,
        );
    }

    public function testItStartsAFamilyWithTheHashAndTheCurrentCredentialsVersionOfTheUser(): void
    {
        // When
        ($this->handler)(new IssueRefreshTokenCommand('user-id', 'token-hash'));

        // Then
        $token = $this->refreshTokenRepository->findOneByTokenHash('token-hash');
        $this->assertInstanceOf(RefreshToken::class, $token);
        $this->assertSame(['user-id', 1], [$token->getUserId(), $token->getCredentialsVersion()]);
        $this->assertEquals($this->clock->now(), $token->getIssuedAt());
        $this->assertFalse($token->isUsed());
    }

    public function testEachConnectionIsItsOwnFamily(): void
    {
        // When
        ($this->handler)(new IssueRefreshTokenCommand('user-id', 'first-hash'));
        ($this->handler)(new IssueRefreshTokenCommand('user-id', 'second-hash'));

        // Then
        $this->assertNotSame(
            $this->refreshTokenRepository->findOneByTokenHash('first-hash')->getFamilyId(),
            $this->refreshTokenRepository->findOneByTokenHash('second-hash')->getFamilyId(),
        );
    }

    public function testTheOldestConnectionsDisappearBeyondTheLimit(): void
    {
        // Given : le plafond est déjà atteint, chaque connexion une seconde après la précédente
        $handlerAt = fn(int $second): IssueRefreshTokenHandler => new IssueRefreshTokenHandler(
            $this->refreshTokenRepository,
            new UserInMemoryRepository([new UserBuilder()->withId('user-id')->build()]),
            new UuidGenerator(),
            new FrozenClock(new DateTimeImmutable('2026-10-14 12:00:00')->modify("+$second seconds")),
        );
        for ($i = 1; $i <= RefreshToken::MAX_FAMILIES_PER_USER; $i++) {
            $handlerAt($i)(new IssueRefreshTokenCommand('user-id', "hash-$i"));
        }

        // When : une connexion de plus
        $handlerAt(100)(new IssueRefreshTokenCommand('user-id', 'hash-newest'));

        // Then : la plus ancienne a disparu, la plus récente est là
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-newest'));
        $this->assertCount(RefreshToken::MAX_FAMILIES_PER_USER, $this->refreshTokenRepository->all());
    }

    public function testAnUnknownUserIsRefused(): void
    {
        // Then
        $this->expectException(UserException::class);

        // When
        ($this->handler)(new IssueRefreshTokenCommand('unknown', 'token-hash'));
    }
}
