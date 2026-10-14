<?php

namespace Repas\Tests\User\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\RefreshTokenInMemoryRepository;
use Repas\User\Application\PurgeExpiredRefreshTokens\PurgeExpiredRefreshTokensCommand;
use Repas\User\Application\PurgeExpiredRefreshTokens\PurgeExpiredRefreshTokensHandler;
use Repas\User\Domain\Model\RefreshToken;

class PurgeExpiredRefreshTokensHandlerTest extends TestCase
{
    public function testItRemovesOnlyTheExpiredTokensAndCountsThem(): void
    {
        // Given : un jeton de 40 jours (expiré) et un de 2 jours
        $now = new DateTimeImmutable('2026-10-14 12:00:00');
        $repository = new RefreshTokenInMemoryRepository([
            RefreshToken::startFamily('old', 'family-old', 'user-id', 'old-hash', 0, $now->modify('-40 days')),
            RefreshToken::startFamily('recent', 'family-recent', 'user-id', 'recent-hash', 0, $now->modify('-2 days')),
        ]);

        // When
        $deleted = new PurgeExpiredRefreshTokensHandler($repository, new FrozenClock($now))(new PurgeExpiredRefreshTokensCommand());

        // Then
        $this->assertSame(1, $deleted);
        $this->assertNull($repository->findOneByTokenHash('old-hash'));
        $this->assertNotNull($repository->findOneByTokenHash('recent-hash'));
    }
}
