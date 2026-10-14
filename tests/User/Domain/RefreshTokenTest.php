<?php

namespace Repas\Tests\User\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Model\RefreshToken;

class RefreshTokenTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-14 12:00:00');
    }

    public function testHashIsADeterministicSha256OfTheSecret(): void
    {
        // When
        $hash = RefreshToken::hash('secret');

        // Then
        $this->assertSame(hash('sha256', 'secret'), $hash);
        $this->assertSame(64, strlen($hash));
        $this->assertNotSame($hash, RefreshToken::hash('Secret'));
    }

    public function testAFamilyStartsWithAThirtyDayTokenInANinetyDayFamily(): void
    {
        // When
        $token = $this->started();

        // Then
        $this->assertSame('2026-11-13 12:00:00', $token->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-12 12:00:00', $token->getFamilyExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($token->isUsed());
        $this->assertFalse($token->isExpired($this->now));
    }

    public function testRotatingConsumesTheTokenAndReturnsItsSuccessorInTheSameFamily(): void
    {
        // Given
        $token = $this->started(credentialsVersion: 3);
        $later = $this->now->modify('+1 day');

        // When
        $next = $token->rotate('next-id', 'next-hash', $later);

        // Then
        $this->assertTrue($token->isUsed());
        $this->assertSame($later, $token->getUsedAt());
        $this->assertFalse($next->isUsed());
        $this->assertSame(['next-id', 'family', 'user', 'next-hash', 3], [$next->getId(), $next->getFamilyId(), $next->getUserId(), $next->getTokenHash(), $next->getCredentialsVersion()]);
        $this->assertSame('2026-11-14 12:00:00', $next->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertEquals($token->getFamilyExpiresAt(), $next->getFamilyExpiresAt());
    }

    public function testASuccessorNeverOutlivesItsFamily(): void
    {
        // Given : la famille se termine dans 5 jours
        $token = $this->started();
        $lastDays = $token->getFamilyExpiresAt()->modify('-5 days');

        // When
        $next = $token->rotate('next-id', 'next-hash', $lastDays);

        // Then
        $this->assertEquals($token->getFamilyExpiresAt(), $next->getExpiresAt());
    }

    public function testATokenExpiresWithItselfOrWithItsFamily(): void
    {
        // Given
        $token = $this->started();

        // Then
        $this->assertFalse($token->isExpired($token->getExpiresAt()->modify('-1 second')));
        $this->assertTrue($token->isExpired($token->getExpiresAt()));
        // Un successeur à la limite de la famille expire avec elle
        $next = $token->rotate('next-id', 'next-hash', $token->getFamilyExpiresAt()->modify('-1 day'));
        $this->assertTrue($next->isExpired($token->getFamilyExpiresAt()));
    }

    public function testAUsedTokenIsJustUsedOnlyDuringTheGracePeriod(): void
    {
        // Given
        $token = $this->started();
        $token->rotate('next-id', 'next-hash', $this->now);

        // Then
        $this->assertTrue($token->wasJustUsed($this->now));
        $this->assertTrue($token->wasJustUsed($this->now->modify('+' . RefreshToken::REUSE_GRACE_SECONDS . ' seconds')));
        $this->assertFalse($token->wasJustUsed($this->now->modify('+' . (RefreshToken::REUSE_GRACE_SECONDS + 1) . ' seconds')));
    }

    public function testAnUnusedTokenWasNeverJustUsed(): void
    {
        $this->assertFalse($this->started()->wasJustUsed($this->now));
    }

    private function started(int $credentialsVersion = 0): RefreshToken
    {
        return RefreshToken::startFamily('token-id', 'family', 'user', 'token-hash', $credentialsVersion, $this->now);
    }
}
