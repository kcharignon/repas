<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Model\AccessGrant;

/**
 * Les paires de jetons sur la vraie base (VOX-202), dont la consommation ATOMIQUE du jeton de rafraîchissement.
 */
class AccessGrantRepositoryTest extends DatabaseTestCase
{
    private AccessGrantRepository $repository;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(AccessGrantRepository::class);
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
    }

    public function testAPairIsSavedAndReadBackByTheHashOfItsRefreshToken(): void
    {
        // Given
        $grant = AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $this->now);

        // When
        $this->repository->add($grant);
        $this->entityManager->clear();

        // Then
        $this->assertEquals($grant, $this->repository->findOneByRefreshHash('refresh-1'));
        $this->assertNull($this->repository->findOneByRefreshHash('access-1'), 'le haché de l\'accès n\'est pas celui du rafraîchissement');
        $this->assertNull($this->repository->findOneByRefreshHash('unknown'));
    }

    public function testTheRefreshTokenIsConsumedOnceAndTheRowStaysToRecognizeAReplay(): void
    {
        // Given
        $this->repository->add(AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $this->now));

        // When
        $first = $this->repository->consumeRefresh('refresh-1', $this->now->modify('+1 hour'));
        $second = $this->repository->consumeRefresh('refresh-1', $this->now->modify('+2 hours'));

        // Then : seul le premier consomme, et la date n'est pas réécrite
        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->entityManager->clear();
        $read = $this->repository->findOneByRefreshHash('refresh-1');
        $this->assertTrue($read->isRefreshUsed());
        $this->assertSame('2026-10-25 13:00:00', $read->getUsedAt()->format('Y-m-d H:i:s'));
    }

    public function testAReadBeforeTheConsumptionDoesNotKeepAStalePairInMemory(): void
    {
        // Given : la paire est lue (donc dans l'identity map), puis consommée par un UPDATE direct
        $this->repository->add(AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $this->now));
        $this->assertFalse($this->repository->findOneByRefreshHash('refresh-1')->isRefreshUsed());
        $this->repository->consumeRefresh('refresh-1', $this->now);

        // When / Then : sans vider l'identity map, la lecture voit la paire consommée
        $this->assertTrue($this->repository->findOneByRefreshHash('refresh-1')->isRefreshUsed());
    }

    public function testConsumingAnUnknownRefreshTokenIsFalse(): void
    {
        $this->assertFalse($this->repository->consumeRefresh('unknown', $this->now));
    }

    public function testDeleteByLinkErasesEveryPairOfThatLinkOnly(): void
    {
        // Given : deux paires de la liaison (dont une consommée), une paire d'une autre
        $this->repository->add(AccessGrant::issue('access-1', 'refresh-1', 'link-1', 'user-1', $this->now));
        $this->repository->add(AccessGrant::issue('access-2', 'refresh-2', 'link-1', 'user-1', $this->now));
        $this->repository->consumeRefresh('refresh-1', $this->now);
        $this->repository->add(AccessGrant::issue('access-3', 'refresh-3', 'link-2', 'user-1', $this->now));

        // When
        $this->repository->deleteByLink('link-1');

        // Then : même sans vider l'identity map, rien ne reste lisible
        $this->assertNull($this->repository->findOneByRefreshHash('refresh-1'));
        $this->assertNull($this->repository->findOneByRefreshHash('refresh-2'));
        $this->assertNotNull($this->repository->findOneByRefreshHash('refresh-3'));
        $this->assertSame(['access-3'], $this->accessHashes());
    }

    public function testDeleteExpiredOfLinkErasesThePairsWhoseRefreshTokenExpiredAndNothingElse(): void
    {
        // Given : une paire de la liaison expirée pile maintenant, une avant, une valable, une expirée d'une autre liaison
        $this->repository->add($this->grantRefreshExpiringAt('expired-now', 'link-1', $this->now));
        $this->repository->add($this->grantRefreshExpiringAt('expired-before', 'link-1', $this->now->modify('-1 day')));
        $this->repository->add($this->grantRefreshExpiringAt('valid', 'link-1', $this->now->modify('+1 second')));
        $this->repository->add($this->grantRefreshExpiringAt('expired-other-link', 'link-2', $this->now->modify('-1 day')));

        // When
        $this->repository->deleteExpiredOfLink('link-1', $this->now);

        // Then
        $this->assertSame(['expired-other-link', 'valid'], $this->accessHashes());
        $this->assertNull($this->repository->findOneByRefreshHash('refresh-of-expired-now'));
    }

    private function grantRefreshExpiringAt(string $accessHash, string $linkId, DateTimeImmutable $refreshExpiresAt): AccessGrant
    {
        return AccessGrant::load([
            'access_hash' => $accessHash,
            'refresh_hash' => 'refresh-of-' . $accessHash,
            'link_id' => $linkId,
            'user_id' => 'user-1',
            'expires_at' => $refreshExpiresAt->modify('-1 day'),
            'refresh_expires_at' => $refreshExpiresAt,
            'used_at' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function accessHashes(): array
    {
        return array_values(array_map('strval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT access_hash FROM voice_access_grant ORDER BY access_hash')));
    }
}
