<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\ActiveUserCheckerInMemory;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\ContextSpyLogger;
use Repas\Tests\Helper\ControlledOpaqueTokenGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\AccessGrantInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\VoiceLinkInMemoryRepository;
use Repas\Voice\Application\Command\IssuedTokens;
use Repas\Voice\Application\Command\RefreshAccessGrant\RefreshAccessGrantCommand;
use Repas\Voice\Application\Command\RefreshAccessGrant\RefreshAccessGrantHandler;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Domain\Service\LinkRevoker;

class RefreshAccessGrantHandlerTest extends TestCase
{
    private const string USER = 'user-1';
    private const string LINK = 'link-1';

    private DateTimeImmutable $issuedAt;
    private DateTimeImmutable $now;
    private VoiceLinkInMemoryRepository $links;
    private AccessGrantInMemoryRepository $grants;
    private ActiveUserCheckerInMemory $activeUsers;
    private ContextSpyLogger $logger;

    protected function setUp(): void
    {
        $this->issuedAt = new DateTimeImmutable('2026-10-25 12:00:00');
        $this->now = $this->issuedAt->modify('+1 hour');
        $this->links = new VoiceLinkInMemoryRepository();
        $this->grants = new AccessGrantInMemoryRepository();
        $this->activeUsers = new ActiveUserCheckerInMemory([self::USER]);
        $this->logger = new ContextSpyLogger();
        $this->links->save(VoiceLink::create(self::LINK, self::USER, $this->issuedAt));
        $this->grants->add(AccessGrant::issue(OpaqueToken::hash(AlexaTestClient::token('access-0')), OpaqueToken::hash(AlexaTestClient::token('refresh-0')), self::LINK, self::USER, $this->issuedAt));
    }

    public function testTheRefreshTokenIsExchangedForANewPairAndConsumed(): void
    {
        // When
        $tokens = $this->refresh('refresh-0');

        // Then : de nouveaux jetons en clair, pour une heure
        $this->assertSame(AlexaTestClient::token('secret-0'), $tokens->accessToken);
        $this->assertSame(AlexaTestClient::token('secret-1'), $tokens->refreshToken);
        $this->assertSame(3600, $tokens->expiresIn);

        // Then : la paire d'origine est consommée mais GARDÉE (pour reconnaître un rejeu), la nouvelle est rangée sous ses hachés, même liaison et même compte
        $grants = $this->grants->all();
        $this->assertCount(2, $grants);
        $this->assertTrue($grants[0]->isRefreshUsed());
        $this->assertFalse($grants[1]->isRefreshUsed());
        $this->assertSame(OpaqueToken::hash($tokens->accessToken), $grants[1]->getId());
        $this->assertSame(OpaqueToken::hash($tokens->refreshToken), $grants[1]->getRefreshHash());
        $this->assertSame([self::LINK, self::USER], [$grants[1]->getLinkId(), $grants[1]->getUserId()]);
        $this->assertSame('2026-10-25 14:00:00', $grants[1]->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($this->links->findOneById(self::LINK)->isRevoked());
        $this->assertStringNotContainsString($tokens->refreshToken, json_encode(array_map(static fn(AccessGrant $grant): array => $grant->toArray(), $grants), JSON_THROW_ON_ERROR));
    }

    public function testTheNewRefreshTokenRotatesAgainAndTheChainKeepsGoing(): void
    {
        // When
        $first = $this->refresh('refresh-0');
        $second = $this->refresh($first->refreshToken, tokens: new ControlledOpaqueTokenGenerator([AlexaTestClient::token('access-2'), AlexaTestClient::token('refresh-2')]));

        // Then
        $this->assertNotNull($second);
        $this->assertSame(AlexaTestClient::token('refresh-2'), $second->refreshToken);
        $this->assertCount(3, $this->grants->all());
    }

    public function testTheRefreshTokenThatAlreadyServedRevokesTheLinkAndAllItsTokens(): void
    {
        // Given : le jeton a déjà servi
        $first = $this->refresh('refresh-0');

        // When : on le présente une seconde fois (Alexa, ou celui qui le lui a pris)
        $replay = $this->refresh('refresh-0');

        // Then : refus, liaison révoquée, tous les jetons effacés, y compris la paire neuve que le premier échange avait émise
        $this->assertNull($replay);
        $this->assertTrue($this->links->findOneById(self::LINK)->isRevoked());
        $this->assertSame([], $this->grants->all());
        $this->assertSame(['warning'], array_column($this->logger->records, 'level'));
        $this->assertStringNotContainsString(AlexaTestClient::token('refresh-0'), $this->logger->everything());
        $this->assertStringNotContainsString($first->refreshToken, $this->logger->everything());
        // le jeton neuf, devenu orphelin, ne sert plus non plus
        $this->assertNull($this->refresh($first->refreshToken));
    }

    public function testAnOldTokenThatServedIsAReplayEvenOnceItsLifetimeIsOver(): void
    {
        // Given : le jeton a servi, et on le présente 200 jours plus tard (un jeton déjà échangé n'a rien à faire dans les mains de personne)
        $this->refresh('refresh-0');

        // When
        $replay = $this->refresh('refresh-0', now: $this->issuedAt->modify('+200 days'));

        // Then : la liaison est révoquée, pas seulement refusée
        $this->assertNull($replay);
        $this->assertTrue($this->links->findOneById(self::LINK)->isRevoked());
        $this->assertSame([], $this->grants->all());
    }

    public function testOfTwoSimultaneousExchangesOfTheSameTokenTheSecondIsAReplay(): void
    {
        // Given : un autre échange du même jeton gagne la course entre la lecture et l'écriture
        $racing = new class extends AccessGrantInMemoryRepository {
            public function consumeRefresh(string $refreshHash, DateTimeImmutable $now): bool
            {
                parent::consumeRefresh($refreshHash, $now);

                return false;
            }
        };
        $racing->add(AccessGrant::issue(OpaqueToken::hash(AlexaTestClient::token('access-0')), OpaqueToken::hash(AlexaTestClient::token('refresh-0')), self::LINK, self::USER, $this->issuedAt));

        // When
        $tokens = new RefreshAccessGrantHandler($racing, $this->links, new LinkRevoker($this->links, $racing), $this->activeUsers, new ControlledOpaqueTokenGenerator(), new FrozenClock($this->now), $this->logger)(
            new RefreshAccessGrantCommand(AlexaTestClient::token('refresh-0')),
        );

        // Then : le perdant est un rejeu, la liaison est révoquée
        $this->assertNull($tokens);
        $this->assertTrue($this->links->findOneById(self::LINK)->isRevoked());
        $this->assertSame([], $racing->all());
    }

    public function testATokenThatIsNotOneOfOursOrUnknownIsRefusedAndNothingChanges(): void
    {
        foreach (['', 'short', str_repeat('a', 43), 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl', AlexaTestClient::token('access-0')] as $token) {
            // When
            $tokens = $this->refreshRaw($token);

            // Then : l'ancien jeton d'ACCÈS n'est pas un jeton de rafraîchissement
            $this->assertNull($tokens, $token);
        }
        $this->assertCount(1, $this->grants->all());
        $this->assertFalse($this->grants->all()[0]->isRefreshUsed());
        $this->assertFalse($this->links->findOneById(self::LINK)->isRevoked());
    }

    public function testAnExpiredRefreshTokenIsRefusedAndNothingChanges(): void
    {
        // Given : 180 jours exactement après l'émission
        $now = $this->issuedAt->modify('+180 days');

        // When
        $tokens = $this->refresh('refresh-0', now: $now);

        // Then
        $this->assertNull($tokens);
        $this->assertCount(1, $this->grants->all());
        $this->assertFalse($this->links->findOneById(self::LINK)->isRevoked());

        // Then : un instant avant, il sert
        $this->assertNotNull($this->refresh('refresh-0', now: $now->modify('-1 second')));
    }

    public function testARevokedLinkRefusesAndItsLeftoverTokensAreErased(): void
    {
        // Given : la liaison a été retirée, mais une paire traîne
        $this->links->save($this->links->findOneById(self::LINK)->revoke($this->issuedAt));

        // When
        $tokens = $this->refresh('refresh-0');

        // Then
        $this->assertNull($tokens);
        $this->assertSame([], $this->grants->all());
    }

    public function testAGrantWhoseLinkBelongsToAnotherAccountIsRefused(): void
    {
        // Given : la paire dit user-1, la liaison est à user-2 (incohérence : rien ne doit servir)
        $this->links->save(VoiceLink::create(self::LINK, 'user-2', $this->issuedAt));

        // When / Then
        $this->assertNull($this->refresh('refresh-0'));
        $this->assertSame([], $this->grants->all());
    }

    public function testAnInactiveAccountGetsNothingAndNothingIsWritten(): void
    {
        // Given : le compte est désactivé ou supprimé
        $this->activeUsers->deactivate(self::USER);

        // When
        $tokens = $this->refresh('refresh-0');

        // Then : refus ; ni jeton neuf, ni jeton consommé, ni liaison révoquée (réactivé, le compte retrouve sa liaison)
        $this->assertNull($tokens);
        $this->assertCount(1, $this->grants->all());
        $this->assertFalse($this->grants->all()[0]->isRefreshUsed());
        $this->assertFalse($this->links->findOneById(self::LINK)->isRevoked());
    }

    public function testThePairsOfTheLinkWhoseRefreshTokenExpiredAreCleanedUpAndOthersStay(): void
    {
        // Given : une vieille paire consommée de cette liaison (expirée), une paire consommée récente, et une paire d'une autre liaison expirée
        $old = $this->issuedAt->modify('-200 days');
        $this->grants->add(AccessGrant::issue('old-access', 'old-refresh', self::LINK, self::USER, $old));
        $this->grants->add(AccessGrant::issue('other-access', 'other-refresh', 'link-2', self::USER, $old));

        // When
        $this->refresh('refresh-0');

        // Then
        $ids = array_map(static fn(AccessGrant $grant): string => $grant->getId(), $this->grants->all());
        $this->assertNotContains('old-access', $ids);
        $this->assertContains('other-access', $ids);
        $this->assertContains(OpaqueToken::hash(AlexaTestClient::token('access-0')), $ids);
    }

    private function refresh(string $seed, ?DateTimeImmutable $now = null, ?ControlledOpaqueTokenGenerator $tokens = null): ?IssuedTokens
    {
        return $this->refreshRaw(AlexaTestClient::token($seed), $now, $tokens);
    }

    private function refreshRaw(string $refreshToken, ?DateTimeImmutable $now = null, ?ControlledOpaqueTokenGenerator $tokens = null): ?IssuedTokens
    {
        $handler = new RefreshAccessGrantHandler(
            $this->grants,
            $this->links,
            new LinkRevoker($this->links, $this->grants),
            $this->activeUsers,
            $tokens ?? new ControlledOpaqueTokenGenerator(),
            new FrozenClock($now ?? $this->now),
            $this->logger,
        );

        return $handler(new RefreshAccessGrantCommand($refreshToken));
    }
}
