<?php

namespace Repas\Tests\User\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028120000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

require_once dirname(__DIR__, 3) . '/migrations/Version20261028120000.php';

/**
 * La migration de l'inscription par la seule adresse (ADR 0020, amendement du 2026-10-28), sur la VRAIE base : le mot de passe d'un compte
 * encore en attente, posé avant la confirmation de l'adresse, est remplacé par « aucun mot de passe » ; les comptes actifs et désactivés ne
 * sont pas touchés.
 */
class LockPendingPasswordsMigrationTest extends DatabaseTestCase
{
    private Connection $connection;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
        $this->users = static::getContainer()->get(UserRepository::class);
    }

    public function testThePasswordOfEveryPendingAccountIsReplacedAndNothingElseIsTouched(): void
    {
        // Given : un compte en attente avec un mot de passe, un autre sans, un actif, un désactivé
        $this->save('attente-avec', fn(UserBuilder $b) => $b->isPending());
        $this->save('attente-sans', fn(UserBuilder $b) => $b->isPending()->withoutPassword());
        $this->save('actif', fn(UserBuilder $b) => $b);
        $this->save('desactive', fn(UserBuilder $b) => $b->isDisabled());
        $hash = $this->passwordOf('actif');
        $before = [$this->versionOf('attente-avec'), $this->versionOf('attente-sans'), $this->versionOf('actif'), $this->versionOf('desactive')];

        // When
        $this->runMigration();

        // Then : seul le compte en attente qui avait un mot de passe change, et ses jetons déjà émis sont révoqués
        $this->assertSame(User::NO_PASSWORD, $this->passwordOf('attente-avec'));
        $this->assertSame($before[0] + 1, $this->versionOf('attente-avec'));
        $this->assertSame([User::NO_PASSWORD, $before[1]], [$this->passwordOf('attente-sans'), $this->versionOf('attente-sans')]);
        $this->assertSame([$hash, $before[2]], [$this->passwordOf('actif'), $this->versionOf('actif')]);
        $this->assertSame([$hash, $before[3]], [$this->passwordOf('desactive'), $this->versionOf('desactive')]);
        $this->assertSame('PENDING', $this->statusOf('attente-avec'), 'le statut ne change pas');
    }

    public function testNoPasswordTheAccountHadBeforeOpensAnythingAfterTheMigration(): void
    {
        // Given
        $this->save('attente', fn(UserBuilder $b) => $b->isPending());
        $user = $this->users->findOneById('attente');
        $this->assertTrue($user->passwordMatch('test'));

        // When
        $this->runMigration();
        $this->entityManager->clear();

        // Then
        $user = $this->users->findOneById('attente');
        $this->assertFalse($user->hasPassword());
        foreach (['test', '', '!', 'un-mot-de-passe'] as $attempt) {
            $this->assertFalse($user->passwordMatch($attempt));
        }
    }

    public function testReplayingItChangesNothing(): void
    {
        // Given
        $this->save('attente', fn(UserBuilder $b) => $b->isPending());
        $this->runMigration();
        $version = $this->versionOf('attente');

        // When
        $this->runMigration();

        // Then : la version des identifiants ne monte pas une seconde fois
        $this->assertSame([User::NO_PASSWORD, $version], [$this->passwordOf('attente'), $this->versionOf('attente')]);
    }

    public function testDownDoesNothingBecauseThePasswordsCannotBeRebuilt(): void
    {
        // Given
        $migration = new Version20261028120000($this->connection, new NullLogger());

        // When
        $migration->down(new Schema());

        // Then : l'instruction neutre, rien d'autre
        $this->assertSame(['SELECT 1'], array_map(static fn($query): string => $query->getStatement(), $migration->getSql()));
    }

    /**
     * @param callable(UserBuilder): UserBuilder $configure
     */
    private function save(string $id, callable $configure): void
    {
        $this->users->save($configure(new UserBuilder()->withId($id)->withEmail($id . '@example.com'))->build());
    }

    private function runMigration(): void
    {
        $migration = new Version20261028120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    private function passwordOf(string $id): string
    {
        return (string) $this->connection->fetchOne('SELECT password FROM "user" WHERE id = ?', [$id]);
    }

    private function versionOf(string $id): int
    {
        return (int) $this->connection->fetchOne('SELECT credentials_version FROM "user" WHERE id = ?', [$id]);
    }

    private function statusOf(string $id): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM "user" WHERE id = ?', [$id]);
    }
}
