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

    public function testRevokingErasesTheAlexaUserIdAndNothingElse(): void
    {
        // Given : une liaison que le webhook a reconnue
        $link = VoiceLink::load([
            'id' => 'link-1',
            'user_id' => 'user-1',
            'alexa_user_id' => 'amzn1.ask.account.TEST',
            'created_at' => new DateTimeImmutable('2026-10-25 10:00:00'),
            'last_used_at' => new DateTimeImmutable('2026-10-25 11:00:00'),
            'revoked_at' => null,
        ]);

        // When
        $link->revoke(new DateTimeImmutable('2026-10-25 12:00:00'));

        // Then : plus d'identifiant (il ne sert qu'à reconnaître une liaison existante), le reste est intact
        $this->assertNull($link->getAlexaUserId());
        $this->assertSame(['link-1', 'user-1', '2026-10-25 10:00:00', '2026-10-25 11:00:00', '2026-10-25 12:00:00'], [
            $link->getId(),
            $link->getUserId(),
            $link->getCreatedAt()->format('Y-m-d H:i:s'),
            $link->getLastUsedAt()->format('Y-m-d H:i:s'),
            $link->getRevokedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function testTheDateOfLastUseIsDueWhenUnknownOrAtLeastTenMinutesOld(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-25 12:00:00');

        // Then : inconnue, 10 minutes pile, plus ancienne : à écrire ; 9 min 59 s, maintenant, dans le futur : non
        $this->assertTrue(VoiceLink::isUseRecordDue(null, $now));
        $this->assertTrue(VoiceLink::isUseRecordDue($now->modify('-600 seconds'), $now));
        $this->assertTrue(VoiceLink::isUseRecordDue($now->modify('-3 days'), $now));
        $this->assertFalse(VoiceLink::isUseRecordDue($now->modify('-599 seconds'), $now));
        $this->assertFalse(VoiceLink::isUseRecordDue($now, $now));
        $this->assertFalse(VoiceLink::isUseRecordDue($now->modify('+1 hour'), $now));
    }
}
