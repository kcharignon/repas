<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\AccessGrantInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\VoiceLinkInMemoryRepository;
use Repas\Voice\Application\Command\RecordVoiceLinkUse\RecordVoiceLinkUseCommand;
use Repas\Voice\Application\Command\RecordVoiceLinkUse\RecordVoiceLinkUseHandler;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;

/**
 * Le suivi d'une liaison à chaque requête authentique d'Alexa (VOX-204) : date de dernier usage au plus toutes les 10 minutes, identifiant
 * d'utilisateur Alexa posé une fois, doublons d'un même Echo révoqués.
 */
class RecordVoiceLinkUseHandlerTest extends TestCase
{
    private const string ME = 'amzn1.ask.account.ME';

    private DateTimeImmutable $now;
    private VoiceLinkInMemoryRepository $links;
    private AccessGrantInMemoryRepository $grants;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
        $this->links = new VoiceLinkInMemoryRepository();
        $this->grants = new AccessGrantInMemoryRepository();
        $this->links->save($this->link('link-1', 'user-1', alexaUserId: null, createdAt: '-3 days'));
    }

    public function testTheFirstUseWritesTheDateAndTheAlexaUserId(): void
    {
        // When
        $this->record(alexaUserId: self::ME, known: null, lastUsedAt: null);

        // Then
        $link = $this->links->findOneById('link-1');
        $this->assertEquals($this->now, $link->getLastUsedAt());
        $this->assertSame(self::ME, $link->getAlexaUserId());
        $this->assertFalse($link->isRevoked());
    }

    public function testAnUseSeenLessThanTenMinutesAgoWritesNothingAtAll(): void
    {
        // Given : un dépôt qui compte ses appels
        $repository = new class extends VoiceLinkInMemoryRepository {
            public int $calls = 0;

            public function recordUse(string $linkId, DateTimeImmutable $at): void
            {
                $this->calls++;
                parent::recordUse($linkId, $at);
            }

            public function identifyAlexaUser(string $linkId, string $alexaUserId): bool
            {
                $this->calls++;

                return parent::identifyAlexaUser($linkId, $alexaUserId);
            }

            public function findOthersOfAlexaUser(string $userId, string $alexaUserId, string $exceptLinkId): array
            {
                $this->calls++;

                return parent::findOthersOfAlexaUser($userId, $alexaUserId, $exceptLinkId);
            }

            public function findOneById(string $id): ?VoiceLink
            {
                $this->calls++;

                return parent::findOneById($id);
            }

            public function save(VoiceLink $link): void
            {
                $this->calls++;
                parent::save($link);
            }
        };

        // When : 9 minutes 59 s après le dernier usage, l'identifiant d'utilisateur Alexa déjà connu
        $this->recordWith($repository, alexaUserId: self::ME, known: self::ME, lastUsedAt: $this->now->modify('-599 seconds'));

        // Then : aucune lecture, aucune écriture
        $this->assertSame(0, $repository->calls);
    }

    public function testTheDateIsWrittenAgainAfterTenMinutes(): void
    {
        // When
        $this->record(alexaUserId: self::ME, known: self::ME, lastUsedAt: $this->now->modify('-600 seconds'));

        // Then
        $this->assertEquals($this->now, $this->links->findOneById('link-1')->getLastUsedAt());
    }

    public function testAnAlexaUserIdThatIsNotSeenInTheRequestIsNotInvented(): void
    {
        // When : requête sans identifiant d'utilisateur Alexa, date récente
        $this->record(alexaUserId: null, known: null, lastUsedAt: $this->now->modify('-1 minute'));

        // Then
        $this->assertNull($this->links->findOneById('link-1')->getAlexaUserId());
        $this->assertNull($this->links->findOneById('link-1')->getLastUsedAt(), 'la date récente n\'est pas réécrite');
    }

    public function testTheAlexaUserIdIsPostedEvenWhenTheDateIsRecent(): void
    {
        // When
        $this->record(alexaUserId: self::ME, known: null, lastUsedAt: $this->now->modify('-1 minute'));

        // Then : l'identifiant est posé, la date ne bouge pas
        $link = $this->links->findOneById('link-1');
        $this->assertSame(self::ME, $link->getAlexaUserId());
        $this->assertNull($link->getLastUsedAt());
    }

    public function testAnAlexaUserIdAlreadyKnownIsNeverChanged(): void
    {
        // Given : la liaison connaît déjà un autre Echo
        $this->links->save($this->link('link-1', 'user-1', alexaUserId: 'amzn1.ask.account.OTHER', createdAt: '-3 days'));

        // When
        $this->record(alexaUserId: self::ME, known: 'amzn1.ask.account.OTHER', lastUsedAt: null);

        // Then
        $this->assertSame('amzn1.ask.account.OTHER', $this->links->findOneById('link-1')->getAlexaUserId());
    }

    public function testTheOtherLinksOfTheSameAccountWithTheSameAlexaUserAreRevokedWithTheirTokens(): void
    {
        // Given : le même Echo relié deux fois (un ancien lien utilisé, un autre déjà retiré), plus des liaisons qui n'ont rien à voir
        $this->links->save($this->link('old', 'user-1', alexaUserId: self::ME, createdAt: '-30 days'));
        $this->links->save($this->link('already-revoked', 'user-1', alexaUserId: self::ME, createdAt: '-20 days', revokedAt: '-10 days'));
        $this->links->save($this->link('other-echo', 'user-1', alexaUserId: 'amzn1.ask.account.OTHER', createdAt: '-30 days'));
        $this->links->save($this->link('other-account', 'user-2', alexaUserId: self::ME, createdAt: '-30 days'));
        $this->grants->add(AccessGrant::issue('old-access', 'old-refresh', 'old', 'user-1', $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('other-echo-access', 'other-echo-refresh', 'other-echo', 'user-1', $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('other-account-access', 'other-account-refresh', 'other-account', 'user-2', $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('new-access', 'new-refresh', 'link-1', 'user-1', $this->now));

        // When : la nouvelle liaison voit l'Echo pour la première fois
        $this->record(alexaUserId: self::ME, known: null, lastUsedAt: null);

        // Then : l'ancienne liaison du même Echo est révoquée, ses jetons effacés ; rien d'autre ne bouge
        $this->assertTrue($this->links->findOneById('old')->isRevoked());
        $this->assertEquals($this->now, $this->links->findOneById('old')->getRevokedAt());
        $this->assertFalse($this->links->findOneById('link-1')->isRevoked());
        $this->assertFalse($this->links->findOneById('other-echo')->isRevoked());
        $this->assertFalse($this->links->findOneById('other-account')->isRevoked());
        $this->assertEquals($this->now->modify('-10 days'), $this->links->findOneById('already-revoked')->getRevokedAt(), 'la première date de révocation est gardée');
        $ids = array_map(static fn(AccessGrant $grant): string => $grant->getId(), $this->grants->all());
        sort($ids);
        $this->assertSame(['new-access', 'other-account-access', 'other-echo-access'], $ids);
    }

    public function testNothingIsRevokedWhenAnotherRequestPostedTheAlexaUserIdFirst(): void
    {
        // Given : la liaison porte déjà l'identifiant en base (une requête simultanée l'a posé), mais cette requête l'ignorait encore
        $this->links->save($this->link('link-1', 'user-1', alexaUserId: self::ME, createdAt: '-3 days'));
        $this->links->save($this->link('old', 'user-1', alexaUserId: self::ME, createdAt: '-30 days'));

        // When
        $this->record(alexaUserId: self::ME, known: null, lastUsedAt: null);

        // Then : la pose n'est pas de cet appel, il ne révoque rien (l'autre requête l'a fait ou le fera)
        $this->assertFalse($this->links->findOneById('old')->isRevoked());
    }

    public function testARevokedLinkIsNeitherIdentifiedNorMarkedAsUsed(): void
    {
        // Given : l'utilisateur a retiré la liaison entre la résolution du jeton et le suivi
        $this->links->save($this->link('link-1', 'user-1', alexaUserId: null, createdAt: '-3 days', revokedAt: '-1 minute'));

        // When
        $this->record(alexaUserId: self::ME, known: null, lastUsedAt: null);

        // Then
        $link = $this->links->findOneById('link-1');
        $this->assertNull($link->getAlexaUserId());
        $this->assertNull($link->getLastUsedAt());
    }

    public function testTheDateNeverMovesBackwards(): void
    {
        // Given : une autre requête a écrit une date plus récente entre-temps
        $this->links->save($this->link('link-1', 'user-1', alexaUserId: self::ME, createdAt: '-3 days', lastUsedAt: '+1 minute'));

        // When
        $this->record(alexaUserId: self::ME, known: self::ME, lastUsedAt: $this->now->modify('-1 hour'));

        // Then
        $this->assertEquals($this->now->modify('+1 minute'), $this->links->findOneById('link-1')->getLastUsedAt());
    }

    private function record(?string $alexaUserId, ?string $known, ?DateTimeImmutable $lastUsedAt): void
    {
        $this->recordWith($this->links, $alexaUserId, $known, $lastUsedAt);
    }

    private function recordWith(VoiceLinkRepository $links, ?string $alexaUserId, ?string $known, ?DateTimeImmutable $lastUsedAt): void
    {
        $handler = new RecordVoiceLinkUseHandler($links, new LinkRevoker($links, $this->grants), new FrozenClock($this->now));

        $handler(new RecordVoiceLinkUseCommand('link-1', 'user-1', $alexaUserId, $known, $lastUsedAt));
    }

    private function link(string $id, string $userId, ?string $alexaUserId, string $createdAt, ?string $revokedAt = null, ?string $lastUsedAt = null): VoiceLink
    {
        return VoiceLink::load([
            'id' => $id,
            'user_id' => $userId,
            'alexa_user_id' => $alexaUserId,
            'created_at' => $this->now->modify($createdAt),
            'last_used_at' => $lastUsedAt === null ? null : $this->now->modify($lastUsedAt),
            'revoked_at' => $revokedAt === null ? null : $this->now->modify($revokedAt),
        ]);
    }
}
