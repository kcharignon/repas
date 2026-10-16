<?php

namespace Repas\Tests\User\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Model\EmailVerificationToken;

class EmailVerificationTokenTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
    }

    public function testHashIsADeterministicSha256OfTheSecret(): void
    {
        $this->assertSame(hash('sha256', 'secret'), EmailVerificationToken::hash('secret'));
        $this->assertNotSame(EmailVerificationToken::hash('secret'), EmailVerificationToken::hash('Secret'));
    }

    public function testALinkIsValidForTwentyFourHours(): void
    {
        // When
        $token = EmailVerificationToken::issue('id', 'user', 'hash', $this->now);

        // Then
        $this->assertSame('2026-10-17 12:00:00', $token->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($token->isExpired($this->now->modify('+23 hours 59 minutes 59 seconds')));
        $this->assertTrue($token->isExpired($this->now->modify('+24 hours')));
    }

    public function testResendingIsTooSoonDuringTheCooldownOnly(): void
    {
        // Given
        $token = EmailVerificationToken::issue('id', 'user', 'hash', $this->now);

        // Then
        $this->assertTrue($token->wasIssuedRecently($this->now));
        $this->assertTrue($token->wasIssuedRecently($this->now->modify('+59 seconds')));
        $this->assertFalse($token->wasIssuedRecently($this->now->modify('+60 seconds')));
    }
}
