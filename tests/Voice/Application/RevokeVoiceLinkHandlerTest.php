<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\AccessGrantInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\VoiceLinkInMemoryRepository;
use Repas\Voice\Application\Command\RevokeVoiceLink\RevokeVoiceLinkCommand;
use Repas\Voice\Application\Command\RevokeVoiceLink\RevokeVoiceLinkHandler;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;

class RevokeVoiceLinkHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private VoiceLinkInMemoryRepository $links;
    private AccessGrantInMemoryRepository $grants;
    private RevokeVoiceLinkHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
        $this->links = new VoiceLinkInMemoryRepository();
        $this->grants = new AccessGrantInMemoryRepository();
        $this->handler = new RevokeVoiceLinkHandler($this->links, new LinkRevoker($this->links, $this->grants), new FrozenClock($this->now));

        $this->links->save(VoiceLink::create('link-1', 'user-1', $this->now->modify('-1 day')));
        $this->links->save(VoiceLink::create('link-2', 'user-1', $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $this->now));
        $this->grants->add(AccessGrant::issue('access-2', 'refresh-2', 'link-2', 'user-1', $this->now));
    }

    public function testTheOwnerRevokesTheLinkAndItsTokens(): void
    {
        // When
        ($this->handler)(new RevokeVoiceLinkCommand('user-1', 'link-1'));

        // Then : la liaison est révoquée à l'heure de l'horloge, ses jetons effacés, l'autre liaison intacte
        $this->assertSame('2026-10-25 12:00:00', $this->links->findOneById('link-1')->getRevokedAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($this->links->findOneById('link-2')->isRevoked());
        $this->assertSame(['access-2'], array_map(static fn(AccessGrant $grant): string => $grant->getId(), $this->grants->all()));
    }

    public function testRevokingTwiceChangesNothingTheSecondTime(): void
    {
        // Given
        ($this->handler)(new RevokeVoiceLinkCommand('user-1', 'link-1'));
        $handler = new RevokeVoiceLinkHandler($this->links, new LinkRevoker($this->links, $this->grants), new FrozenClock($this->now->modify('+1 hour')));

        // When
        $handler(new RevokeVoiceLinkCommand('user-1', 'link-1'));

        // Then
        $this->assertSame('2026-10-25 12:00:00', $this->links->findOneById('link-1')->getRevokedAt()->format('Y-m-d H:i:s'));
    }

    public function testAnotherUsersLinkIsNotFoundAndNothingChanges(): void
    {
        // Then
        $this->expectException(VoiceException::class);
        $this->expectExceptionMessage(VoiceException::LINK_NOT_FOUND);

        try {
            // When
            ($this->handler)(new RevokeVoiceLinkCommand('user-2', 'link-1'));
        } finally {
            $this->assertFalse($this->links->findOneById('link-1')->isRevoked());
            $this->assertCount(2, $this->grants->all());
        }
    }

    public function testAnUnknownLinkIsNotFound(): void
    {
        $this->expectException(VoiceException::class);
        $this->expectExceptionMessage(VoiceException::LINK_NOT_FOUND);

        ($this->handler)(new RevokeVoiceLinkCommand('user-1', 'unknown'));
    }
}
