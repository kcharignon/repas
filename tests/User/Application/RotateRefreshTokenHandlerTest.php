<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\RotateRefreshToken\RotateRefreshTokenCommand;
use Repas\User\Application\RotateRefreshToken\RotateRefreshTokenHandler;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Model\User;
use Symfony\Component\ErrorHandler\BufferingLogger;

class RotateRefreshTokenHandlerTest extends TestCase
{
    private const string NOW = '2026-10-14 12:00:00';

    private RefreshTokenInMemoryRepository $refreshTokenRepository;
    private BufferingLogger $logger;
    private User $user;

    protected function setUp(): void
    {
        $this->refreshTokenRepository = new RefreshTokenInMemoryRepository();
        $this->logger = new BufferingLogger();
        $this->user = new UserBuilder()->withId('user-id')->withEmail('alexiane@example.com')->build();
    }

    public function testItConsumesTheTokenSavesItsSuccessorAndReturnsTheLoginOfTheOwner(): void
    {
        // Given
        $this->started('family', 'hash-1');

        // When
        $result = $this->rotate('hash-1', 'hash-2');

        // Then
        $this->assertSame('alexiane@example.com', $result);
        $this->assertTrue($this->refreshTokenRepository->findOneByTokenHash('hash-1')->isUsed());
        $next = $this->refreshTokenRepository->findOneByTokenHash('hash-2');
        $this->assertSame(['family', 'user-id', false], [$next->getFamilyId(), $next->getUserId(), $next->isUsed()]);
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->assertNull($this->rotate('unknown', 'hash-2'));
        $this->assertSame([], $this->refreshTokenRepository->all());
    }

    public function testReplayingAUsedTokenRevokesTheWholeFamily(): void
    {
        // Given : le jeton a été échangé il y a une minute, son successeur circule
        $this->started('family', 'hash-1');
        $this->rotate('hash-1', 'hash-2', at: '2026-10-14 11:59:00');
        $this->started('other-family', 'other-hash');

        // When : le jeton ancien est présenté de nouveau
        $result = $this->rotate('hash-1', 'hash-3');

        // Then : refus, et le successeur est révoqué lui aussi ; les autres connexions restent
        $this->assertNull($result);
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-3'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('other-hash'));
        $this->assertSame('warning', $this->logger->cleanLogs()[0][0]);
    }

    public function testAJustUsedTokenIsRefusedWithoutRevokingTheFamily(): void
    {
        // Given : deux onglets envoient le même cookie à quelques secondes d'écart
        $this->started('family', 'hash-1');
        $this->rotate('hash-1', 'hash-2', at: '2026-10-14 11:59:55');

        // When
        $result = $this->rotate('hash-1', 'hash-3');

        // Then : le perdant est refusé, mais le successeur que le navigateur a reçu reste valable
        $this->assertNull($result);
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-3'));
        $this->assertSame([], $this->logger->cleanLogs());
    }

    public function testAnExpiredTokenIsRefusedAndItsFamilyRemoved(): void
    {
        // Given
        $this->started('family', 'hash-1', at: '2026-09-01 12:00:00');

        // When
        $result = $this->rotate('hash-1', 'hash-2');

        // Then
        $this->assertNull($result);
        $this->assertSame([], $this->refreshTokenRepository->all());
    }

    public function testATokenIssuedBeforeAPasswordChangeIsRefusedAndItsFamilyRemoved(): void
    {
        // Given
        $this->started('family', 'hash-1');
        $this->user->changePassword('nouveau-hash');

        // When
        $result = $this->rotate('hash-1', 'hash-2');

        // Then
        $this->assertNull($result);
        $this->assertSame([], $this->refreshTokenRepository->all());
    }

    public function testARehashOfTheSamePasswordDoesNotRevokeTheToken(): void
    {
        // Given
        $this->started('family', 'hash-1');
        $this->user->setPassword('hash-recalcule');

        // When & Then
        $this->assertSame('alexiane@example.com', $this->rotate('hash-1', 'hash-2'));
    }

    public function testADisabledAccountIsRefusedAndItsFamilyRemoved(): void
    {
        // Given
        $this->started('family', 'hash-1');
        $this->user = new UserBuilder()->withId('user-id')->isDisabled()->build();

        // When
        $result = $this->rotate('hash-1', 'hash-2');

        // Then
        $this->assertNull($result);
        $this->assertSame([], $this->refreshTokenRepository->all());
    }

    public function testATokenOfADeletedUserIsRefusedAndItsFamilyRemoved(): void
    {
        // Given
        $this->refreshTokenRepository->save(RefreshToken::startFamily('token-id', 'family', 'deleted-user', 'hash-1', 0, new DateTimeImmutable(self::NOW)));

        // When
        $result = $this->rotate('hash-1', 'hash-2');

        // Then
        $this->assertNull($result);
        $this->assertSame([], $this->refreshTokenRepository->all());
    }

    private function started(string $familyId, string $hash, string $at = self::NOW): void
    {
        $this->refreshTokenRepository->save(RefreshToken::startFamily(
            id: "id-$hash",
            familyId: $familyId,
            userId: 'user-id',
            tokenHash: $hash,
            credentialsVersion: $this->user->getCredentialsVersion(),
            now: new DateTimeImmutable($at),
        ));
    }

    private function rotate(string $presented, string $next, string $at = self::NOW): ?string
    {
        $handler = new RotateRefreshTokenHandler(
            $this->refreshTokenRepository,
            new UserInMemoryRepository([$this->user]),
            new UuidGenerator(),
            new FrozenClock(new DateTimeImmutable($at)),
            $this->logger,
        );

        return $handler(new RotateRefreshTokenCommand($presented, $next));
    }
}
