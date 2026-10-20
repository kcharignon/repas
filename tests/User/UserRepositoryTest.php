<?php

namespace Repas\Tests\User;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;

class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }


    public function testInsertAndUpdateUser(): void
    {
        //Arrange
        $user = new UserBuilder()
            ->withEmail('test@test.com')
            ->build();

        //Act
        $this->userRepository->save($user);
        $this->entityManager->flush();

        //Assert
        $actual = $this->userRepository->findOneByEmail($user->getEmail());
        RepasAssert::assertUser($user, $actual);

        //Act
        $user->setEmail('test2@test.com');
        $user->setDefaultServing(8);
        $this->userRepository->save($user);

        //Assert
        $actual = $this->userRepository->findOneByEmail($user->getEmail());
        RepasAssert::assertUser($user, $actual);
    }


    public function testEmailExistsOnlyForASavedEmail(): void
    {
        //Arrange
        $this->userRepository->save(new UserBuilder()->withEmail('known@test.com')->build());

        //Act & Assert
        $this->assertTrue($this->userRepository->emailExists('known@test.com'));
        $this->assertFalse($this->userRepository->emailExists('unknown@test.com'));
    }

    public function testTheEmailIsFoundWhateverItsCase(): void
    {
        //Arrange
        $user = new UserBuilder()->withEmail('known@test.com')->build();
        $this->userRepository->save($user);

        //Act & Assert
        $this->assertTrue($this->userRepository->emailExists('Known@Test.COM'));
        $this->assertSame($user->getId(), $this->userRepository->findOneByEmail('  KNOWN@test.com ')->getId());
    }

    public function testTheSameEmailSavedTwiceGivesAnEmailAlreadyUsedError(): void
    {
        //Arrange : deux inscriptions simultanées ont passé le contrôle d'existence
        $this->userRepository->save(new UserBuilder()->withEmail('twice@test.com')->build());

        //Assert
        $this->expectExceptionObject(UserException::emailAlreadyUsed('twice@test.com'));

        //Act
        $this->userRepository->save(new UserBuilder()->withEmail('twice@test.com')->build());
    }

    public function testADeletedUserIsGoneAndTheOthersStay(): void
    {
        //Arrange
        $leaving = new UserBuilder()->withEmail('leaving@test.com')->build();
        $staying = new UserBuilder()->withEmail('staying@test.com')->build();
        $this->userRepository->save($leaving);
        $this->userRepository->save($staying);

        //Act
        $this->userRepository->delete($leaving);
        $this->entityManager->clear();

        //Assert
        $this->assertFalse($this->userRepository->emailExists('leaving@test.com'));
        $this->assertTrue($this->userRepository->emailExists('staying@test.com'));
        $this->expectExceptionObject(UserException::NotFound($leaving->getId()));
        $this->userRepository->findOneById($leaving->getId());
    }

    public function testDeletingAnUnknownUserIsNotAnError(): void
    {
        //Act
        $this->userRepository->delete(new UserBuilder()->build());

        //Assert
        $this->assertSame(0, $this->userRepository->findAll()->count());
    }

    public function testAStatusChangeIsPersisted(): void
    {
        //Arrange
        $user = new UserBuilder()->isPending()->build();
        $this->userRepository->save($user);

        //Act
        $user->changeStatus(UserStatus::DISABLED);
        $this->userRepository->save($user);
        $this->entityManager->clear();

        //Assert
        $this->assertTrue($this->userRepository->findOneById($user->getId())->isDisabled());
    }

    public function testRecordingALoginWritesTheDateAndNothingElse(): void
    {
        //Arrange
        $user = new UserBuilder()->withEmail('login@test.com')->withServing(6)->isAdmin()->build();
        $this->userRepository->save($user);
        $this->entityManager->clear();
        $before = $this->rowOf($user->getId());
        $at = new DateTimeImmutable('2026-10-20 08:30:12');

        //Act
        $this->userRepository->recordLogin($user->getId(), $at);
        $this->entityManager->clear();

        //Assert : la dernière connexion a la date donnée, toutes les autres colonnes sont intactes
        $after = $this->rowOf($user->getId());
        $this->assertSame('2026-10-20 08:30:12', $after['last_login_at']);
        unset($before['last_login_at'], $after['last_login_at']);
        $this->assertSame($before, $after);
        $this->assertEquals($at, $this->userRepository->findOneById($user->getId())->getLastLoginAt());
    }

    public function testRecordingALoginIsOneStatementWithoutAnyRead(): void
    {
        //Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        //Act
        $this->userRepository->recordLogin($user->getId(), new DateTimeImmutable('2026-10-20 08:30:12'));

        //Assert : un UPDATE de la seule colonne, aucun SELECT du compte
        $this->assertSame(1, $counter->count());
        $this->assertMatchesRegularExpression('/^UPDATE "user" SET last_login_at = /', $counter->statements()[0]);
        $this->assertStringNotContainsString('status', $counter->statements()[0]);
        $this->assertStringNotContainsString('password', $counter->statements()[0]);
    }

    public function testRecordingALoginDoesNotEraseWhatAnotherRequestChangedMeanwhile(): void
    {
        //Arrange : cette requête a chargé le compte actif ; entre-temps un administrateur l'a désactivé
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $loaded = $this->userRepository->findOneById($user->getId());
        $this->connection()->executeStatement("UPDATE \"user\" SET status = 'DISABLED', credentials_version = 7 WHERE id = :id", ['id' => $user->getId()]);

        //Act
        $this->userRepository->recordLogin($loaded->getId(), new DateTimeImmutable('2026-10-20 08:30:12'));
        $this->entityManager->clear();

        //Assert : la date est écrite, le statut et la version des identifiants posés par l'autre requête sont intacts
        $row = $this->rowOf($user->getId());
        $this->assertSame('2026-10-20 08:30:12', $row['last_login_at']);
        $this->assertSame('DISABLED', $row['status']);
        $this->assertSame(7, $row['credentials_version']);
    }

    public function testSavingAModelLoadedBeforeALoginDoesNotEraseTheLogin(): void
    {
        //Arrange : cette requête a chargé le compte (sans connexion) ; une autre requête enregistre la connexion
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $stale = $this->userRepository->findOneById($user->getId());
        $this->userRepository->recordLogin($user->getId(), new DateTimeImmutable('2026-10-20 08:30:12'));

        //Act : cette requête enregistre son modèle périmé (dernier enregistrement gagnant, sauf pour la connexion)
        $stale->setDefaultServing(8);
        $this->userRepository->save($stale);
        $this->entityManager->clear();

        //Assert
        $found = $this->userRepository->findOneById($user->getId());
        $this->assertSame(8, $found->getDefaultServing());
        $this->assertEquals(new DateTimeImmutable('2026-10-20 08:30:12'), $found->getLastLoginAt());
    }

    public function testSavingAnExistingAccountNeverWritesTheLastLoginOfTheModel(): void
    {
        //Arrange : le compte existe sans connexion ; un modèle de ce compte porte une date (builder), jamais passée par recordLogin()
        $this->userRepository->save(new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->build());
        $model = new UserBuilder()->withId('11111111-1111-4111-8111-111111111111')->withLastLoginAt(new DateTimeImmutable('2026-10-20 08:30:12'))->build();

        //Act
        $this->userRepository->save($model);
        $this->entityManager->clear();

        //Assert : seul recordLogin() écrit la colonne d'un compte existant
        $this->assertNull($this->userRepository->findOneById('11111111-1111-4111-8111-111111111111')->getLastLoginAt());
    }

    public function testANewAccountIsInsertedWithTheDateOfItsModel(): void
    {
        //Arrange : un compte créé avec une date (jeu d'essai) ; un vrai compte naît à null
        $user = new UserBuilder()->withLastLoginAt(new DateTimeImmutable('2026-10-20 08:30:12'))->build();

        //Act
        $this->userRepository->save($user);
        $this->entityManager->clear();

        //Assert
        $this->assertEquals(new DateTimeImmutable('2026-10-20 08:30:12'), $this->userRepository->findOneById($user->getId())->getLastLoginAt());
    }

    public function testARegisteredAccountHasNoLastLoginYet(): void
    {
        //Arrange
        $user = User::register('22222222-2222-4222-8222-222222222222', 'inscrit@test.com', ['ROLE_USER'], 'hash', 3, new DateTimeImmutable('2026-10-20 08:00:00'));

        //Act
        $this->userRepository->save($user);
        $this->entityManager->clear();

        //Assert
        $this->assertNull($this->userRepository->findOneById($user->getId())->getLastLoginAt());
        $this->assertNull($this->rowOf($user->getId())['last_login_at']);
    }

    public function testTheLastLoginNeverGoesBack(): void
    {
        //Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $this->userRepository->recordLogin($user->getId(), new DateTimeImmutable('2026-10-20 09:00:00'));

        //Act : une connexion plus ancienne arrive après (deux requêtes qui se croisent)
        $this->userRepository->recordLogin($user->getId(), new DateTimeImmutable('2026-10-20 08:00:00'));
        $this->entityManager->clear();

        //Assert
        $this->assertEquals(new DateTimeImmutable('2026-10-20 09:00:00'), $this->userRepository->findOneById($user->getId())->getLastLoginAt());
    }

    public function testTheDateIsStoredInTheDefaultTimezoneWhateverTheOneOfTheDate(): void
    {
        //Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);

        //Act : 10:30 à Paris (UTC+2 en octobre) est 08:30 en UTC, le fuseau de PHP et de la base
        $this->userRepository->recordLogin($user->getId(), new DateTimeImmutable('2026-10-20 10:30:12', new DateTimeZone('Europe/Paris')));
        $this->entityManager->clear();

        //Assert
        $this->assertSame('2026-10-20 08:30:12', $this->rowOf($user->getId())['last_login_at']);
        $this->assertSame('2026-10-20T08:30:12+00:00', $this->userRepository->findOneById($user->getId())->getLastLoginAt()->format(DATE_ATOM));
    }

    public function testRecordingALoginForAnUnknownAccountCreatesNothing(): void
    {
        //Act : compte supprimé entre l'authentification et l'enregistrement
        $this->userRepository->recordLogin('00000000-0000-4000-8000-000000000000', new DateTimeImmutable('2026-10-20 08:30:12'));

        //Assert
        $this->assertSame(0, $this->userRepository->findAll()->count());
    }

    public function testDeletingAnAccountDeletesItsLastLoginWithIt(): void
    {
        //Arrange
        $leaving = new UserBuilder()->withEmail('leaving@test.com')->build();
        $this->userRepository->save($leaving);
        $this->userRepository->recordLogin($leaving->getId(), new DateTimeImmutable('2026-10-20 08:30:12'));

        //Act
        $this->userRepository->delete($leaving);
        $this->entityManager->clear();

        //Assert : la date est sur la ligne du compte, elle part avec lui ; un enregistrement tardif ne la fait pas renaître
        $this->userRepository->recordLogin($leaving->getId(), new DateTimeImmutable('2026-10-20 09:00:00'));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = :id OR last_login_at IS NOT NULL', ['id' => $leaving->getId()]));
    }

    public function testUserNotFound(): void
    {
        //Assert
        $this->expectExceptionObject(UserException::NotFound('unknown@test.com'));

        //Act
        $this->userRepository->findOneByEmail('unknown@test.com');
    }

    /**
     * La ligne telle qu'elle est en base (SQL direct, hors de l'ORM).
     *
     * @return array<string, mixed>
     */
    private function rowOf(string $id): array
    {
        return $this->connection()->fetchAssociative('SELECT * FROM "user" WHERE id = :id', ['id' => $id]);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
