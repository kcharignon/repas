<?php

namespace Repas\Tests\Voice\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\VoiceLink;

class VoiceLinkTest extends TestCase
{
    public function testANewLinkKnowsNothingOfAlexaYetAndIsNotRevoked(): void
    {
        // When
        $link = VoiceLink::create('link-1', 'user-1', new DateTimeImmutable('2026-10-25 12:00:00'));

        // Then
        $this->assertSame(['link-1', 'user-1', null, null, null], [$link->getId(), $link->getUserId(), $link->getAlexaUserId(), $link->getLastUsedAt(), $link->getRevokedAt()]);
        $this->assertSame('2026-10-25 12:00:00', $link->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($link->isRevoked());
    }

    public function testRevokingKeepsTheDateOfTheFirstRevocation(): void
    {
        // Given
        $link = VoiceLink::create('link-1', 'user-1', new DateTimeImmutable('2026-10-25 12:00:00'));

        // When
        $link->revoke(new DateTimeImmutable('2026-10-25 13:00:00'));
        $link->revoke(new DateTimeImmutable('2026-10-25 14:00:00'));

        // Then
        $this->assertTrue($link->isRevoked());
        $this->assertSame('2026-10-25 13:00:00', $link->getRevokedAt()->format('Y-m-d H:i:s'));
    }
}
