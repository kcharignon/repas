<?php

namespace Repas\Tests\User\OutputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Model\PasswordResetToken;

/**
 * Chaque test crée ses jetons (la base de test est vide au départ).
 */
class PasswordResetTokenRepositoryTest extends DatabaseTestCase
{
    private PasswordResetTokenRepository $repository;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(PasswordResetTokenRepository::class);
        $this->now = new DateTimeImmutable('2026-10-19 12:00:00');
    }

    public function testASavedTokenIsFoundByItsHashAndByItsUserWithAllItsFields(): void
    {
        // Arrange
        $token = PasswordResetToken::issue('token-id', 'user-id', PasswordResetToken::hash('secret'), $this->now);

        // Act
        $this->repository->add($token);
        $this->clearIdentityMap();

        // Assert
        $this->assertEquals($token, $this->repository->findOneByTokenHash(PasswordResetToken::hash('secret')));
        $this->clearIdentityMap();
        $this->assertEquals($token, $this->repository->findOneByUserId('user-id'));
    }

    public function testAnUnknownHashOrUserIsNull(): void
    {
        $this->assertNull($this->repository->findOneByTokenHash('unknown'));
        $this->assertNull($this->repository->findOneByUserId('unknown'));
    }

    public function testDeletingAUsersTokensKeepsTheOthers(): void
    {
        // Arrange
        $this->repository->add(PasswordResetToken::issue('token-1', 'user-1', 'hash-1', $this->now));
        $this->repository->add(PasswordResetToken::issue('token-2', 'user-2', 'hash-2', $this->now));

        // Act
        $this->repository->deleteAllOfUser('user-1');

        // Assert : même sans vider l'identity map, la ligne supprimée n'est plus rendue
        $this->assertNull($this->repository->findOneByTokenHash('hash-1'));
        $this->assertNull($this->repository->findOneByUserId('user-1'));
        $this->assertNotNull($this->repository->findOneByTokenHash('hash-2'));
        $this->clearIdentityMap();
        $this->assertNull($this->repository->findOneByTokenHash('hash-1'));
        $this->assertNotNull($this->repository->findOneByTokenHash('hash-2'));
    }

    private function clearIdentityMap(): void
    {
        static::getContainer()->get('doctrine')->getManager()->clear();
    }
}
