<?php

namespace Repas\Tests\User\OutputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Repas\Tests\Helper\Builder\UserAppVersionBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Interface\UserRepository;

/**
 * Chaque test crée ses comptes et leurs versions (la base de test est vide au départ).
 */
class UserAppVersionRepositoryTest extends DatabaseTestCase
{
    private UserAppVersionRepository $repository;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(UserAppVersionRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testASavedVersionIsStoredWithItsPlatformAndItsTimeAndNothingElse(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-1')->build());

        // When
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->withVersion('0.1.8')->withSeenAt(new DateTimeImmutable('2026-10-20 10:15:42'))->build());

        // Then
        $this->assertSame(
            [['user_id' => 'user-1', 'platform' => 'android', 'version' => '0.1.8', 'seen_at' => '2026-10-20 10:15:42']],
            $this->connection()->fetchAllAssociative('SELECT * FROM user_app_version'),
        );
    }

    public function testANewVersionReplacesThePreviousOneOfTheSamePlatform(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-1')->build());
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->withVersion('0.1.8')->withSeenAt(new DateTimeImmutable('2026-10-19 08:00:00'))->build());

        // When
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->withVersion('0.1.10')->withSeenAt(new DateTimeImmutable('2026-10-20 10:00:00'))->build());

        // Then : une ligne par compte et par plateforme, pas d'historique
        $this->assertSame(
            [['user_id' => 'user-1', 'version' => '0.1.10', 'seen_at' => '2026-10-20 10:00:00']],
            $this->connection()->fetchAllAssociative('SELECT user_id, version, seen_at FROM user_app_version'),
        );
    }

    public function testSavingTheSameVersionAgainOnlyMovesTheTime(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-1')->build());
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->withVersion('0.1.8')->withSeenAt(new DateTimeImmutable('2026-10-19 08:00:00'))->build());

        // When
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->withVersion('0.1.8')->withSeenAt(new DateTimeImmutable('2026-10-20 10:00:00'))->build());

        // Then
        $this->assertSame(
            [['version' => '0.1.8', 'seen_at' => '2026-10-20 10:00:00']],
            $this->connection()->fetchAllAssociative('SELECT version, seen_at FROM user_app_version'),
        );
    }

    public function testNothingIsWrittenForAnAccountThatDoesNotExist(): void
    {
        // Given : un compte supprimé (ou jamais créé)
        $this->userRepository->save(new UserBuilder()->withId('user-1')->build());
        $this->userRepository->delete($this->userRepository->findOneById('user-1'));

        // When
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->build());
        $this->repository->save(new UserAppVersionBuilder()->withUserId('jamais-cree')->build());

        // Then : la ligne ne renaît pas
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM user_app_version'));
    }

    public function testAVersionIsWrittenInOneQueryWithoutReadingFirst(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-1')->build());
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->withVersion('0.1.8')->build());
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->withVersion('0.1.10')->build());

        // Then
        $this->assertSame(['INSERT user_app_version' => 1], $counter->byTable());
    }

    public function testTheAccountRowIsLockedUntilTheEndOfTheTransaction(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-1')->build());
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->build());

        // Then : une suppression du compte concurrente attend la fin de l'écriture (puis l'efface), au lieu de la laisser renaître
        $this->assertStringContainsString('FOR KEY SHARE', $counter->statements()[0]);
        $this->assertSame(
            ['user'],
            $this->connection()->fetchFirstColumn(
                "SELECT c.relname FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE l.pid = pg_backend_pid() AND l.mode = 'RowShareLock' AND c.relname = 'user'",
            ),
        );
    }

    public function testDeletingTheVersionsOfAnAccountKeepsTheOthers(): void
    {
        // Given
        $this->userRepository->save(new UserBuilder()->withId('user-1')->build());
        $this->userRepository->save(new UserBuilder()->withId('user-2')->build());
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-1')->build());
        $this->repository->save(new UserAppVersionBuilder()->withUserId('user-2')->build());
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->repository->deleteAllOfUser('user-1');

        // Then : une seule requête
        $this->assertSame(['DELETE user_app_version' => 1], $counter->byTable());
        $this->assertSame(['user-2'], $this->connection()->fetchFirstColumn('SELECT user_id FROM user_app_version'));
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }
}
