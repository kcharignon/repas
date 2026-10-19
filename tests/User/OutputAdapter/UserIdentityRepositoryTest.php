<?php

namespace Repas\Tests\User\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Repas\Tests\Helper\Builder\UserIdentityBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Interface\UserIdentityRepository;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\UserIdentity;

/**
 * Chaque test crée ses liens (la base de test est vide au départ). Sujets factices uniquement : jamais le `sub` d'un vrai
 * compte Google.
 */
class UserIdentityRepositoryTest extends DatabaseTestCase
{
    private UserIdentityRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(UserIdentityRepository::class);
    }

    public function testASavedLinkIsFoundByItsSubjectAndByItsAccountWithAllItsFields(): void
    {
        // Given
        $identity = UserIdentity::create('identity-id', 'user-id', IdentityProvider::GOOGLE, 'sujet-test-1', new DateTimeImmutable('2026-10-20 09:15:42'));

        // When
        $this->repository->save($identity);
        $this->clearIdentityMap();

        // Then
        $this->assertEquals($identity, $this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-1'));
        $this->clearIdentityMap();
        $this->assertEquals($identity, $this->repository->findOneByUserAndProvider('user-id', IdentityProvider::GOOGLE));
        $this->assertSame(
            ['id' => 'identity-id', 'user_id' => 'user-id', 'provider' => 'GOOGLE', 'subject' => 'sujet-test-1', 'created_at' => '2026-10-20 09:15:42'],
            $this->connection()->fetchAssociative('SELECT id, user_id, provider, subject, created_at FROM user_identity'),
        );
    }

    public function testTheSubjectIsComparedExactly(): void
    {
        // Given
        $this->repository->save(new UserIdentityBuilder()->withSubject('sujet-test-A')->build());

        // Then : ni la casse ni un espace ne désignent la même personne
        $this->assertNull($this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-a'));
        $this->assertNull($this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-A '));
        $this->assertNotNull($this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-A'));
    }

    public function testAnUnknownSubjectOrAnAccountWithoutLinkIsNull(): void
    {
        // Given
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-1')->withSubject('sujet-test-1')->build());

        // Then
        $this->assertNull($this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-inconnu'));
        $this->assertNull($this->repository->findOneByUserAndProvider('user-2', IdentityProvider::GOOGLE));
    }

    public function testASubjectAlreadyLinkedToAnAccountCannotBeLinkedToAnother(): void
    {
        // Given : deux connexions simultanées pour le même sujet ont toutes deux vu « aucun lien »
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-1')->withSubject('sujet-test-1')->build());

        // When
        try {
            $this->repository->save(new UserIdentityBuilder()->withUserId('user-2')->withSubject('sujet-test-1')->build());
            $this->fail('Un sujet ne se lie qu\'à un seul compte.');
        } catch (IdentityException $exception) {
            // Then : un conflit, et seul le premier lien existe
            $this->assertEquals(IdentityException::alreadyLinked(), $exception);
            $this->assertSame(409, $exception->getCode());
            $this->assertSame([['user_id' => 'user-1', 'subject' => 'sujet-test-1']], $this->connection()->fetchAllAssociative('SELECT user_id, subject FROM user_identity'));
        }
    }

    public function testAnAccountCannotHaveTwoLinksWithTheSameProvider(): void
    {
        // Given
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-1')->withSubject('sujet-test-1')->build());

        // When
        try {
            $this->repository->save(new UserIdentityBuilder()->withUserId('user-1')->withSubject('sujet-test-2')->build());
            $this->fail('Un compte n\'a qu\'un lien Google.');
        } catch (IdentityException $exception) {
            // Then
            $this->assertEquals(IdentityException::alreadyLinked(), $exception);
            $this->assertSame(409, $exception->getCode());
            $this->assertSame([['user_id' => 'user-1', 'subject' => 'sujet-test-1']], $this->connection()->fetchAllAssociative('SELECT user_id, subject FROM user_identity'));
        }
    }

    public function testANewLinkIsInsertedWithoutBeingLookedForFirst(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->save(new UserIdentityBuilder()->build());

        // Then
        $this->assertSame(['INSERT user_identity' => 1], $counter->byTable());
    }

    public function testSavingALinkReadInTheSameRequestDoesNotInsertItTwice(): void
    {
        // Given
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-1')->withSubject('sujet-test-1')->build());
        $this->clearIdentityMap();
        $found = $this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-1');
        $this->assertNotNull($found);

        // When
        $this->repository->save($found);

        // Then
        $this->assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM user_identity'));
    }

    public function testDeletingTheLinksOfAnAccountKeepsTheOthers(): void
    {
        // Given
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-1')->withSubject('sujet-test-1')->build());
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-2')->withSubject('sujet-test-2')->build());
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->deleteAllOfUser('user-1');

        // Then : une seule requête, et même sans vider l'identity map, le lien supprimé n'est plus rendu
        $this->assertSame(['DELETE user_identity' => 1], $counter->byTable());
        $this->assertNull($this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-1'));
        $this->assertNull($this->repository->findOneByUserAndProvider('user-1', IdentityProvider::GOOGLE));
        $this->assertNotNull($this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-2'));
        $this->clearIdentityMap();
        $this->assertNull($this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-1'));
        $this->assertSame('user-2', $this->repository->findOneByUserAndProvider('user-2', IdentityProvider::GOOGLE)?->getUserId());
    }

    public function testAnAccountWhoseLinksWereDeletedCanBeLinkedAgain(): void
    {
        // Given
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-1')->withSubject('sujet-test-1')->build());
        $this->repository->deleteAllOfUser('user-1');

        // When : le même sujet, à un autre compte (le compte supprimé a pu être recréé)
        $this->repository->save(new UserIdentityBuilder()->withUserId('user-3')->withSubject('sujet-test-1')->build());

        // Then
        $this->assertSame('user-3', $this->repository->findOneByProviderAndSubject(IdentityProvider::GOOGLE, 'sujet-test-1')?->getUserId());
    }

    private function clearIdentityMap(): void
    {
        static::getContainer()->get('doctrine')->getManager()->clear();
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }
}
