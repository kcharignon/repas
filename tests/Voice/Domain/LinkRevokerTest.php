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
