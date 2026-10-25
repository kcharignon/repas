<?php

namespace Repas\Tests\Voice\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Voice\Domain\Interface\UserVoiceDataRepository;
use Repas\Voice\Infrastructure\Entity\AccessGrant;
use Repas\Voice\Infrastructure\Entity\AuthorizationCode;
use Repas\Voice\Infrastructure\Entity\VoiceLink;

/**
 * Effacement des données vocales d'un compte sur la vraie base (VOX-201, ADR 0021). Les lignes sont écrites par les entités de
 * mapping : cela prouve aussi qu'elles s'enregistrent (colonnes, types, index) avant que VOX-202 n'y mette un modèle.
 */
class UserVoiceDataRepositoryTest extends DatabaseTestCase
{
    private UserVoiceDataRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(UserVoiceDataRepository::class);
    }

    public function testDeleteByUserErasesLinksTokensAndCodesOfThatUserOnly(): void
    {
        // Given : deux comptes qui ont chacun deux liaisons (avec leurs jetons) et deux codes, dont un usé
        $this->saveLink('link-departing-1', 'departing', 'amzn-departing-1');
        $this->saveLink('link-departing-2', 'departing', null);
        $this->saveLink('link-staying-1', 'staying', 'amzn-staying-1');
        $this->saveLink('link-staying-2', 'staying', null);
        $this->saveGrant('departing-access-1', 'link-departing-1');
        $this->saveGrant('departing-access-2', 'link-departing-2');
        $this->saveGrant('staying-access-1', 'link-staying-1');
        $this->saveGrant('staying-access-2', 'link-staying-2');
        $this->saveCode('departing-code-1', 'departing', false);
        $this->saveCode('departing-code-2', 'departing', true);
        $this->saveCode('staying-code-1', 'staying', false);
        $this->saveCode('staying-code-2', 'staying', true);

        // When
        $this->repository->deleteByUser('departing');

        // Then : plus rien du compte parti, aucune ligne orpheline, tout le reste intact
        $this->assertSame(0, $this->rows('voice_link', 'user_id', 'departing'));
        $this->assertSame(0, $this->rows('voice_authorization_code', 'user_id', 'departing'));
        $this->assertSame(0, $this->rows('voice_access_grant', 'link_id', 'link-departing-1'));
        $this->assertSame(0, $this->rows('voice_access_grant', 'link_id', 'link-departing-2'));
        $this->assertSame(2, $this->rows('voice_link', 'user_id', 'staying'));
        $this->assertSame(2, $this->rows('voice_authorization_code', 'user_id', 'staying'));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM voice_access_grant'));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM voice_access_grant WHERE link_id NOT IN (SELECT id FROM voice_link)'));
    }

    public function testDeleteByUserErasesTheTokensOfARevokedLinkToo(): void
    {
        // Given : une liaison retirée par l'utilisateur, dont les jetons existent encore
        $this->saveLink('link-revoked', 'departing', 'amzn-departing', revoked: true);
        $this->saveGrant('departing-access', 'link-revoked');

        // When
        $this->repository->deleteByUser('departing');

        // Then
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM voice_link'));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM voice_access_grant'));
    }

    public function testAUserWithoutVoiceDataIsNotAnError(): void
    {
        // Given : seul un autre compte a des données
        $this->saveLink('link-staying', 'staying', 'amzn-staying');
        $this->saveGrant('staying-access', 'link-staying');
        $this->saveCode('staying-code', 'staying', false);

        // When
        $this->repository->deleteByUser('nobody');

        // Then
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM voice_link'));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM voice_access_grant'));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM voice_authorization_code'));
    }

    public function testTheErasureCostsOneStatementWhateverTheNumberOfRows(): void
    {
        // Given
        $this->saveLink('link-departing', 'departing', null);
        $this->saveGrant('departing-access', 'link-departing');
        $this->saveCode('departing-code', 'departing', false);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->deleteByUser('departing');

        // Then
        $this->assertSame(1, $counter->count());
    }

    private function saveLink(string $id, string $userId, ?string $alexaUserId, bool $revoked = false): void
    {
        $link = new VoiceLink();
        $link->id = $id;
        $link->userId = $userId;
        $link->alexaUserId = $alexaUserId;
        $link->createdAt = new DateTimeImmutable('2026-10-25 10:00:00');
        $link->revokedAt = $revoked ? new DateTimeImmutable('2026-10-25 11:00:00') : null;
        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }

    private function saveGrant(string $accessHash, string $linkId): void
    {
        $grant = new AccessGrant();
        $grant->accessHash = $accessHash;
        $grant->refreshHash = 'refresh-of-' . $accessHash;
        $grant->linkId = $linkId;
        $grant->expiresAt = new DateTimeImmutable('2026-10-25 11:00:00');
        $grant->refreshExpiresAt = new DateTimeImmutable('2026-11-25 10:00:00');
        $this->entityManager->persist($grant);
        $this->entityManager->flush();
    }

    private function saveCode(string $codeHash, string $userId, bool $used): void
    {
        $code = new AuthorizationCode();
        $code->codeHash = $codeHash;
        $code->userId = $userId;
        $code->clientId = 'repas-alexa';
        $code->redirectUri = 'https://redirect.example.test/callback';
        $code->codeChallenge = 'challenge-' . $codeHash;
        $code->expiresAt = new DateTimeImmutable('2026-10-25 10:10:00');
        $code->usedAt = $used ? new DateTimeImmutable('2026-10-25 10:05:00') : null;
        $this->entityManager->persist($code);
        $this->entityManager->flush();
    }

    private function rows(string $table, string $column, string $value): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM $table WHERE $column = :value", ['value' => $value]);
    }

    private function scalar(string $sql): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql);
    }
}
