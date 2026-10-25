<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Voice\Domain\Interface\AuthorizationCodeRepository;
use Repas\Voice\Domain\Model\AuthorizationCode;

/**
 * Les codes d'autorisation sur la vraie base (VOX-202), dont l'usage unique ATOMIQUE.
 */
class AuthorizationCodeRepositoryTest extends DatabaseTestCase
{
    private AuthorizationCodeRepository $repository;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(AuthorizationCodeRepository::class);
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
    }

    public function testACodeIsSavedAndReadBackByItsHash(): void
    {
        // Given
        $code = $this->code('hash-1', 'user-1');

        // When
        $this->repository->add($code);
        $this->entityManager->clear();
        $read = $this->repository->findOneByHash('hash-1');

        // Then
        $this->assertEquals($code, $read);
        $this->assertNull($this->repository->findOneByHash('unknown'));
    }

    public function testTheCodeIsConsumedOnceAndTheLinkIsRemembered(): void
    {
        // Given
        $this->repository->add($this->code('hash-1', 'user-1'));

        // When
        $first = $this->repository->consume('hash-1', $this->now, 'link-1');
        $second = $this->repository->consume('hash-1', $this->now->modify('+1 second'), 'link-2');

        // Then : seul le premier consomme ; le second ne change rien (ni la date, ni la liaison)
        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->entityManager->clear();
        $read = $this->repository->findOneByHash('hash-1');
        $this->assertTrue($read->isUsed());
        $this->assertSame('2026-10-25 12:00:00', $read->getUsedAt()->format('Y-m-d H:i:s'));
        $this->assertSame('link-1', $read->getLinkId());
    }

    public function testAReadBeforeTheConsumptionDoesNotKeepAStaleCodeInMemory(): void
    {
        // Given : le code est lu (donc dans l'identity map), puis consommé par un UPDATE direct
        $this->repository->add($this->code('hash-1', 'user-1'));
        $this->assertFalse($this->repository->findOneByHash('hash-1')->isUsed());
        $this->repository->consume('hash-1', $this->now, null);

        // When : sans vider l'identity map
        $read = $this->repository->findOneByHash('hash-1');

        // Then : la lecture voit le code consommé
        $this->assertTrue($read->isUsed());
    }

    public function testACodeBurnedByARefusedExchangeHasNoLink(): void
    {
        // Given
        $this->repository->add($this->code('hash-1', 'user-1'));

        // When
        $this->repository->consume('hash-1', $this->now, null);
        $this->entityManager->clear();

        // Then
        $read = $this->repository->findOneByHash('hash-1');
        $this->assertTrue($read->isUsed());
        $this->assertNull($read->getLinkId());
    }

    public function testConsumingAnUnknownCodeIsFalse(): void
    {
        $this->assertFalse($this->repository->consume('unknown', $this->now, 'link-1'));
    }

    public function testTheExpiredCodesOfTheUserAreErasedAndNobodyElsesStay(): void
    {
        // Given : un code expiré pile maintenant, un expiré avant, un valable, et un expiré d'un autre compte
        $this->repository->add($this->codeExpiringAt('expired-now', 'user-1', $this->now));
        $this->repository->add($this->codeExpiringAt('expired-before', 'user-1', $this->now->modify('-1 hour')));
        $this->repository->add($this->codeExpiringAt('valid', 'user-1', $this->now->modify('+1 second')));
        $this->repository->add($this->codeExpiringAt('expired-other-user', 'user-2', $this->now->modify('-1 hour')));

        // When
        $this->repository->deleteExpiredOfUser('user-1', $this->now);

        // Then
        $this->assertSame(['expired-other-user', 'valid'], $this->hashes());
    }

    private function code(string $hash, string $userId): AuthorizationCode
    {
        return AuthorizationCode::issue($hash, $userId, 'repas-alexa', 'https://alexa.example.test/link', str_repeat('c', 43), $this->now);
    }

    private function codeExpiringAt(string $hash, string $userId, DateTimeImmutable $expiresAt): AuthorizationCode
    {
        return AuthorizationCode::load([
            'code_hash' => $hash,
            'user_id' => $userId,
            'client_id' => 'repas-alexa',
            'redirect_uri' => 'https://alexa.example.test/link',
            'code_challenge' => str_repeat('c', 43),
            'expires_at' => $expiresAt,
            'used_at' => null,
            'link_id' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function hashes(): array
    {
        $hashes = $this->entityManager->getConnection()->fetchFirstColumn('SELECT code_hash FROM voice_authorization_code ORDER BY code_hash');

        return array_values(array_map('strval', $hashes));
    }
}
