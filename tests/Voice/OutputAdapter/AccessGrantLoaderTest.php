<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Voice\Application\Loader\AccessGrantLoader;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\VoiceLink;

/**
 * La résolution d'un jeton d'accès sur la vraie base (VOX-202) : le jeton existe, n'est pas expiré, sa liaison n'est pas révoquée et
 * est du même compte. Une seule requête.
 */
class AccessGrantLoaderTest extends DatabaseTestCase
{
    private AccessGrantLoader $loader;
    private AccessGrantRepository $grants;
    private VoiceLinkRepository $links;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(AccessGrantLoader::class);
        $this->grants = static::getContainer()->get(AccessGrantRepository::class);
        $this->links = static::getContainer()->get(VoiceLinkRepository::class);
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
        $this->links->save(VoiceLink::create('link-1', 'user-1', $this->now->modify('-1 day')));
        $this->grants->add(AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $this->now));
    }

    public function testAValidAccessTokenResolvesToItsAccountAndLink(): void
    {
        $this->assertEquals(new ResolvedAccessGrantDTO('user-1', 'link-1'), $this->loader->resolve('access-1', $this->now->modify('+10 minutes')));
    }

    public function testTheResolutionCarriesWhatTheLinkAlreadyKnowsOfAlexa(): void
    {
        // Given : une liaison qu'Alexa a déjà utilisée
        $this->links->save(VoiceLink::load(['id' => 'link-1', 'user_id' => 'user-1', 'alexa_user_id' => 'amzn1.ask.account.TEST', 'created_at' => $this->now->modify('-1 day'), 'last_used_at' => $this->now->modify('-5 minutes'), 'revoked_at' => null]));
        $this->entityManager->clear();

        // When
        $resolved = $this->loader->resolve('access-1', $this->now);

        // Then : de quoi juger, sans requête de plus, s'il faut compléter l'identifiant ou rafraîchir la date
        $this->assertSame(['user-1', 'link-1', 'amzn1.ask.account.TEST'], [$resolved->userId, $resolved->linkId, $resolved->alexaUserId]);
        $this->assertEquals($this->now->modify('-5 minutes'), $resolved->lastUsedAt);
    }

    public function testAnUnknownHashResolvesToNothing(): void
    {
        $this->assertNull($this->loader->resolve('unknown', $this->now));
        $this->assertNull($this->loader->resolve('refresh-1', $this->now), 'le haché du jeton de rafraîchissement n\'ouvre rien');
    }

    public function testTheTokenIsExpiredAtTheSecondItExpires(): void
    {
        $this->assertNotNull($this->loader->resolve('access-1', $this->now->modify('+3599 seconds')));
        $this->assertNull($this->loader->resolve('access-1', $this->now->modify('+3600 seconds')));
    }

    public function testARevokedLinkResolvesToNothing(): void
    {
        // Given
        $this->links->save($this->links->findOneById('link-1')->revoke($this->now));

        // When / Then
        $this->assertNull($this->loader->resolve('access-1', $this->now));
    }

    public function testALinkOfAnotherAccountResolvesToNothing(): void
    {
        // Given : incohérence — la paire dit user-1, la liaison est à user-2
        $this->grants->add(AccessGrant::issue('access-2', 'refresh-2', 'link-2', 'user-1', $this->now));
        $this->links->save(VoiceLink::create('link-2', 'user-2', $this->now));

        // When / Then
        $this->assertNull($this->loader->resolve('access-2', $this->now));
    }

    public function testAnOrphanPairWithoutALinkResolvesToNothing(): void
    {
        $this->grants->add(AccessGrant::issue('access-3', 'refresh-3', 'no-such-link', 'user-1', $this->now));

        $this->assertNull($this->loader->resolve('access-3', $this->now));
    }

    public function testTheAccessTokenOfAPairWhoseRefreshTokenWasExchangedStaysValidUntilItsOwnExpiry(): void
    {
        // Given : Alexa a rafraîchi, la paire d'origine est consommée
        $this->grants->consumeRefresh('refresh-1', $this->now->modify('+50 minutes'));

        // When / Then : Amazon veut que l'ancien jeton d'accès serve jusqu'à `expires_in`
        $this->assertNotNull($this->loader->resolve('access-1', $this->now->modify('+55 minutes')));
        $this->assertNull($this->loader->resolve('access-1', $this->now->modify('+61 minutes')));
    }

    public function testResolvingCostsASingleQuery(): void
    {
        // Given
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->loader->resolve('access-1', $this->now);

        // Then
        $this->assertSame(1, $counter->count());
    }
}
