<?php

namespace Repas\Tests\Voice\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryRepository\AccessGrantInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\VoiceLinkInMemoryRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;

class LinkRevokerTest extends TestCase
{
    public function testTheLinkIsRevokedAndAllItsGrantsAreErasedAndNothingElse(): void
    {
        // Given : deux liaisons, chacune avec une paire consommée et une paire en cours
        $now = new DateTimeImmutable('2026-10-25 12:00:00');
        $links = new VoiceLinkInMemoryRepository();
        $grants = new AccessGrantInMemoryRepository();
        $links->save(VoiceLink::create('link-1', 'user-1', $now));
        $links->save(VoiceLink::create('link-2', 'user-1', $now));
        foreach (['link-1', 'link-2'] as $linkId) {
            $grants->add(AccessGrant::issue("old-access-$linkId", "old-refresh-$linkId", $linkId, 'user-1', $now));
            $grants->consumeRefresh("old-refresh-$linkId", $now);
            $grants->add(AccessGrant::issue("access-$linkId", "refresh-$linkId", $linkId, 'user-1', $now));
        }

        // When
        new LinkRevoker($links, $grants)->revoke($links->findOneById('link-1'), $now->modify('+1 hour'));

        // Then
        $this->assertTrue($links->findOneById('link-1')->isRevoked());
        $this->assertFalse($links->findOneById('link-2')->isRevoked());
        $this->assertSame(['old-access-link-2', 'access-link-2'], array_map(static fn(AccessGrant $grant): string => $grant->getId(), $grants->all()));
    }

    public function testTheAlexaUserIdIsErasedWithTheRevocation(): void
    {
        // Given : deux liaisons que le webhook a reconnues
        $now = new DateTimeImmutable('2026-10-25 12:00:00');
        $links = new VoiceLinkInMemoryRepository();
        $grants = new AccessGrantInMemoryRepository();
        foreach (['link-1', 'link-2'] as $id) {
            $links->save(VoiceLink::load(['id' => $id, 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => $now, 'last_used_at' => $now, 'revoked_at' => null]));
        }

        // When
        new LinkRevoker($links, $grants)->revoke($links->findOneById('link-1'), $now->modify('+1 hour'));

        // Then : l'identifiant de la liaison retirée est effacé, celui de l'autre est intact
        $this->assertNull($links->findOneById('link-1')->getAlexaUserId());
        $this->assertSame('amzn1.ask.account.TEST', $links->findOneById('link-2')->getAlexaUserId());
    }

    public function testALinkRevokedBeforeTheIdWasErasedIsCleanedAndKeepsItsDate(): void
    {
        // Given : une ancienne ligne révoquée qui porte encore son identifiant
        $now = new DateTimeImmutable('2026-10-25 12:00:00');
        $links = new VoiceLinkInMemoryRepository();
        $links->save(VoiceLink::load(['id' => 'link-1', 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => $now, 'last_used_at' => null, 'revoked_at' => $now]));

        // When
        new LinkRevoker($links, new AccessGrantInMemoryRepository())->revoke($links->findOneById('link-1'), $now->modify('+1 hour'));

        // Then
        $this->assertNull($links->findOneById('link-1')->getAlexaUserId());
        $this->assertSame('2026-10-25 12:00:00', $links->findOneById('link-1')->getRevokedAt()->format('Y-m-d H:i:s'));
    }

    public function testRevokingAnAlreadyRevokedLinkKeepsItsDateAndStillErasesItsGrants(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-25 12:00:00');
        $links = new VoiceLinkInMemoryRepository();
        $grants = new AccessGrantInMemoryRepository();
        $links->save(VoiceLink::create('link-1', 'user-1', $now)->revoke($now));
        $grants->add(AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $now));

        // When
        new LinkRevoker($links, $grants)->revoke($links->findOneById('link-1'), $now->modify('+1 hour'));

        // Then
        $this->assertSame('2026-10-25 12:00:00', $links->findOneById('link-1')->getRevokedAt()->format('Y-m-d H:i:s'));
        $this->assertSame([], $grants->all());
    }
}
