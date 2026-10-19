<?php

namespace Repas\Tests\User\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\PasswordResetToken;

class PasswordResetTokenTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-19 12:00:00');
    }

    public function testHashIsADeterministicSha256OfTheSecret(): void
    {
        $this->assertSame(hash('sha256', 'secret'), PasswordResetToken::hash('secret'));
        $this->assertNotSame(PasswordResetToken::hash('secret'), PasswordResetToken::hash('Secret'));
    }

    public function testALinkIsValidForThirtyMinutes(): void
    {
        // When
        $token = PasswordResetToken::issue('id', 'user', 'hash', $this->now);

        // Then
        $this->assertSame('2026-10-19 12:30:00', $token->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($token->isExpired($this->now));
        $this->assertFalse($token->isExpired($this->now->modify('+29 minutes 59 seconds')));
        $this->assertTrue($token->isExpired($this->now->modify('+30 minutes')));
    }

    public function testAskingAgainIsTooSoonDuringTheCooldownOnly(): void
    {
        // Given
        $token = PasswordResetToken::issue('id', 'user', 'hash', $this->now);

        // Then
        $this->assertTrue($token->wasIssuedRecently($this->now));
        $this->assertTrue($token->wasIssuedRecently($this->now->modify('+59 seconds')));
        $this->assertFalse($token->wasIssuedRecently($this->now->modify('+60 seconds')));
    }

    public function testAResetLinkIsMuchShorterThanAnAddressConfirmationLink(): void
    {
        $reset = new \DateInterval(PasswordResetToken::TTL);
        $verification = new \DateInterval(EmailVerificationToken::TTL);

        $this->assertLessThan(
            $this->now->add($verification)->getTimestamp(),
            $this->now->add($reset)->getTimestamp(),
        );
    }
}
