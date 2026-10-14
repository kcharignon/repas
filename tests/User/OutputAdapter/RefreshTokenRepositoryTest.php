<?php

namespace Repas\Tests\User\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Model\RefreshToken;

/**
 * Chaque test crée ses jetons (la base de test est vide au départ).
 */
class RefreshTokenRepositoryTest extends DatabaseTestCase
{
    private RefreshTokenRepository $refreshTokenRepository;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshTokenRepository = static::getContainer()->get(RefreshTokenRepository::class);
        $this->now = new DateTimeImmutable('2026-10-14 12:00:00');
    }

    public function testASavedTokenIsFoundByItsHashWithAllItsFields(): void
    {
        // Arrange
        $token = RefreshToken::startFamily('token-id', 'family-id', 'user-id', RefreshToken::hash('secret'), 4, $this->now);

        // Act
        $this->refreshTokenRepository->save($token);
        $this->clearIdentityMap();
        $loaded = $this->refreshTokenRepository->findOneByTokenHash(RefreshToken::hash('secret'));

        // Assert
        $this->assertInstanceOf(RefreshToken::class, $loaded);
        $this->assertEquals($token, $loaded);
    }

    public function testAnUnknownHashIsNull(): void
    {
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('unknown'));
    }

    public function testRotatingPersistsTheUsedTokenAndItsSuccessor(): void
    {
        // Arrange
        $token = RefreshToken::startFamily('token-id', 'family-id', 'user-id', 'hash-1', 0, $this->now);
        $this->refreshTokenRepository->save($token);
        $next = $token->rotate('next-id', 'hash-2', $this->now->modify('+1 hour'));

        // Act
        $this->refreshTokenRepository->save($token);
        $this->refreshTokenRepository->save($next);
        $this->clearIdentityMap();

        // Assert
        $this->assertEquals($this->now->modify('+1 hour'), $this->refreshTokenRepository->findOneByTokenHash('hash-1')->getUsedAt());
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-2')->getUsedAt());
    }

    public function testDeleteFamilyRemovesEveryTokenOfTheFamilyAndOnlyThose(): void
    {
        // Arrange
        $first = RefreshToken::startFamily('id-1', 'family', 'user-id', 'hash-1', 0, $this->now);
        $second = $first->rotate('id-2', 'hash-2', $this->now);
        foreach ([$first, $second, RefreshToken::startFamily('id-3', 'other', 'user-id', 'hash-3', 0, $this->now)] as $token) {
            $this->refreshTokenRepository->save($token);
        }

        // Act
        $this->refreshTokenRepository->deleteFamily('family');

        // Assert : même sans vider l'identity map, les jetons supprimés ne ressortent pas
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-2'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-3'));
    }

    public function testATokenCanBeSavedAgainAfterItsFamilyWasDeleted(): void
    {
        // Arrange : l'entité chargée par le premier enregistrement ne doit pas survivre à la suppression par DQL
        $token = RefreshToken::startFamily('token-id', 'family', 'user-id', 'hash-1', 0, $this->now);
        $this->refreshTokenRepository->save($token);
        $this->refreshTokenRepository->deleteFamily('family');

        // Act
        $this->refreshTokenRepository->save($token);
        $this->clearIdentityMap();

        // Assert
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-1'));
    }

    public function testDeleteOldestFamiliesOfUserKeepsTheMostRecentlyActiveOnes(): void
    {
        // Arrange : trois familles de l'utilisateur, dont l'une a un jeton récent (activité) ; une famille d'un autre
        $oldest = RefreshToken::startFamily('id-old', 'family-old', 'user-id', 'hash-old', 0, $this->now->modify('-3 hours'));
        $active = RefreshToken::startFamily('id-active', 'family-active', 'user-id', 'hash-active', 0, $this->now->modify('-4 hours'));
        $middle = RefreshToken::startFamily('id-middle', 'family-middle', 'user-id', 'hash-middle', 0, $this->now->modify('-2 hours'));
        $activeNext = $active->rotate('id-active-2', 'hash-active-2', $this->now->modify('-1 hour'));
        $theirs = RefreshToken::startFamily('id-theirs', 'family-theirs', 'someone-else', 'hash-theirs', 0, $this->now->modify('-9 hours'));
        foreach ([$oldest, $active, $activeNext, $middle, $theirs] as $token) {
            $this->refreshTokenRepository->save($token);
        }

        // Act
        $this->refreshTokenRepository->deleteOldestFamiliesOfUser('user-id', 2);

        // Assert : la famille la moins récemment active a disparu, avec tous ses jetons
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-old'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-active'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-active-2'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-middle'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-theirs'));
    }

    public function testDeleteExpiredRemovesTheExpiredTokensAndCountsThem(): void
    {
        // Arrange : un jeton de 40 jours (expiré), un de 2 jours
        $old = RefreshToken::startFamily('id-old', 'family-old', 'user-id', 'hash-old', 0, $this->now->modify('-40 days'));
        $recent = RefreshToken::startFamily('id-recent', 'family-recent', 'user-id', 'hash-recent', 0, $this->now->modify('-2 days'));
        $this->refreshTokenRepository->save($old);
        $this->refreshTokenRepository->save($recent);

        // Act
        $deleted = $this->refreshTokenRepository->deleteExpired($this->now);

        // Assert
        $this->assertSame(1, $deleted);
        $this->assertNull($this->refreshTokenRepository->findOneByTokenHash('hash-old'));
        $this->assertNotNull($this->refreshTokenRepository->findOneByTokenHash('hash-recent'));
    }

    private function clearIdentityMap(): void
    {
        static::getContainer()->get('doctrine')->getManager()->clear();
    }
}
