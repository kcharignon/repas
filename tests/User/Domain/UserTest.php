<?php

namespace Repas\Tests\User\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

class UserTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-16 12:00:00');
    }

    public function testARegisteredVisitorIsPendingUntilHeConfirmsHisEmail(): void
    {
        // When
        $user = User::register('id', ' Alexiane@Example.COM ', ['ROLE_USER'], 3, $this->now);

        // Then
        $this->assertSame(UserStatus::PENDING, $user->getStatus());
        $this->assertTrue($user->isPending());
        $this->assertFalse($user->isDisabled());
        $this->assertSame('alexiane@example.com', $user->getEmail());
    }

    public function testARegisteredVisitorHasNoPasswordUntilHeChoosesOneFromTheEmailedLink(): void
    {
        // When
        $user = User::register('id', 'alexiane@example.com', ['ROLE_USER'], 3, $this->now);

        // Then : ni le visiteur ni personne ne connaît de mot de passe pour ce compte
        $this->assertFalse($user->hasPassword());
        $this->assertSame(User::NO_PASSWORD, $user->getPassword());
        $this->assertSame(0, $user->getCredentialsVersion());
        foreach (['', '!', 'un-mot-de-passe', str_repeat('a', 72), User::NO_PASSWORD . User::NO_PASSWORD] as $attempt) {
            $this->assertFalse($user->passwordMatch($attempt), sprintf('« %s » ne doit pas ouvrir le compte', $attempt));
        }
    }

    public function testNoPasswordIsNotAHashAndNoHasherAcceptsAnyInputAgainstIt(): void
    {
        // Then : « ! » n'est le hachage d'aucun algorithme, ni pour PHP ni pour le hacheur natif de Symfony (« auto »)
        $this->assertNull(password_get_info(User::NO_PASSWORD)['algo']);
        $hasher = new NativePasswordHasher();
        foreach (['', '!', 'un-mot-de-passe'] as $attempt) {
            $this->assertFalse($hasher->verify(User::NO_PASSWORD, $attempt));
        }
    }

    public function testAnAccountWithAHashedPasswordHasOne(): void
    {
        $this->assertTrue(new UserBuilder()->build()->hasPassword());
        $this->assertTrue(User::create('id', 'a@example.com', ['ROLE_USER'], 'hash', 3, $this->now)->hasPassword());
    }

    public function testLockingThePasswordRemovesItAndRevokesTheIssuedTokens(): void
    {
        // Given : un compte en attente de l'ancien formulaire, avec le mot de passe qu'un tiers a posé
        $user = new UserBuilder()->isPending()->build();
        $version = $user->getCredentialsVersion();
        $this->assertTrue($user->passwordMatch('test'), 'avant : ce mot de passe ouvre le compte');

        // When
        $user->lockPassword();

        // Then
        $this->assertFalse($user->hasPassword());
        $this->assertFalse($user->passwordMatch('test'));
        $this->assertSame($version + 1, $user->getCredentialsVersion());
    }

    public function testLockingThePasswordOfAnAccountThatHasNoneChangesNothing(): void
    {
        // Given
        $user = User::register('id', 'alexiane@example.com', ['ROLE_USER'], 3, $this->now);

        // When
        $user->lockPassword();

        // Then : la version des identifiants ne monte pas pour rien
        $this->assertSame(0, $user->getCredentialsVersion());
        $this->assertFalse($user->hasPassword());
    }

    public function testChoosingAPasswordGivesTheAccountOne(): void
    {
        // Given
        $user = User::register('id', 'alexiane@example.com', ['ROLE_USER'], 3, $this->now);

        // When
        $user->changePassword(password_hash('un-mot-de-passe', PASSWORD_BCRYPT, ['cost' => 4]));

        // Then
        $this->assertTrue($user->hasPassword());
        $this->assertTrue($user->passwordMatch('un-mot-de-passe'));
    }

    public function testAnAccountCreatedOutsideRegistrationIsActive(): void
    {
        // When
        $user = User::create('id', 'alexiane@example.com', ['ROLE_USER'], 'hash', 3, $this->now);

        // Then
        $this->assertSame(UserStatus::ACTIVE, $user->getStatus());
        $this->assertFalse($user->isPending());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidServings(): iterable
    {
        yield 'zéro' => [0];
        yield 'négatif' => [-1];
        yield 'cent un' => [101];
    }

    #[DataProvider('invalidServings')]
    public function testAnAccountCannotBeCreatedForAnInvalidNumberOfPeople(int $serving): void
    {
        // Then
        $this->expectExceptionObject(UserException::invalidServing());

        // When
        User::register('id', 'alexiane@example.com', ['ROLE_USER'], $serving, $this->now);
    }

    #[DataProvider('invalidServings')]
    public function testTheDefaultNumberOfPeopleCannotBeChangedToAnInvalidOne(int $serving): void
    {
        // Given
        $user = new UserBuilder()->build();

        // Then
        $this->expectExceptionObject(UserException::invalidServing());

        // When
        $user->setDefaultServing($serving);
    }

    public function testTheDefaultNumberOfPeopleGoesFromOneToAHundred(): void
    {
        // When / Then
        $this->assertSame(1, User::create('id', 'a@example.com', ['ROLE_USER'], 'hash', 1, $this->now)->getDefaultServing());
        $this->assertSame(100, new UserBuilder()->build()->setDefaultServing(100)->getDefaultServing());
    }

    public function testConfirmingTheEmailActivatesAPendingAccount(): void
    {
        // Given
        $user = new UserBuilder()->isPending()->build();

        // When
        $user->verifyEmail();

        // Then
        $this->assertSame(UserStatus::ACTIVE, $user->getStatus());
        $this->assertFalse($user->isPending());
    }

    public function testConfirmingTheEmailNeverReactivatesADisabledAccount(): void
    {
        // Given
        $user = new UserBuilder()->isDisabled()->build();

        // When
        $user->verifyEmail();

        // Then
        $this->assertSame(UserStatus::DISABLED, $user->getStatus());
    }

    public function testConfirmingTheEmailOfAnActiveAccountChangesNothing(): void
    {
        // Given
        $user = new UserBuilder()->build();

        // When
        $user->verifyEmail();

        // Then
        $this->assertSame(UserStatus::ACTIVE, $user->getStatus());
    }

    #[DataProvider('everyChangeOfStatus')]
    public function testTheStatusCanChangeToAnyOtherOne(UserStatus $from, UserStatus $to): void
    {
        // Given
        $user = $this->userWithStatus($from);

        // When
        $user->changeStatus($to);

        // Then
        $this->assertSame($to, $user->getStatus());
        $this->assertSame($to === UserStatus::ACTIVE, $user->isActive());
        $this->assertSame($to === UserStatus::DISABLED, $user->isDisabled());
        $this->assertSame($to === UserStatus::PENDING, $user->isPending());
    }

    /**
     * Les neuf couples, y compris rester dans le même statut.
     *
     * @return iterable<string, array{UserStatus, UserStatus}>
     */
    public static function everyChangeOfStatus(): iterable
    {
        foreach (UserStatus::cases() as $from) {
            foreach (UserStatus::cases() as $to) {
                yield "$from->value vers $to->value" => [$from, $to];
            }
        }
    }

    #[DataProvider('statusesOfAProtectedAccount')]
    public function testTheProtectedAccountIsUntouchableButCanBeActive(UserStatus $from): void
    {
        // Given
        $user = $this->userWithStatus($from, User::PROTECTED_EMAIL);

        // When
        $user->changeStatus(UserStatus::ACTIVE);

        // Then : rétablir l'accès est permis, quel que soit l'état de départ
        $this->assertSame(UserStatus::ACTIVE, $user->getStatus());
    }

    #[DataProvider('protectedAccountChanges')]
    public function testTheProtectedAccountCannotBeDisabledNorPutPending(UserStatus $from, UserStatus $to): void
    {
        // Given
        $user = $this->userWithStatus($from, User::PROTECTED_EMAIL);

        // When
        try {
            $user->changeStatus($to);
            $this->fail('Le compte protégé est intouchable.');
        } catch (UserException $exception) {
            // Then
            $this->assertEquals(UserException::protectedAccount(), $exception);
            $this->assertSame(422, $exception->getCode());
            $this->assertSame($from, $user->getStatus());
        }
    }

    /**
     * @return iterable<string, array{UserStatus, UserStatus}>
     */
    public static function protectedAccountChanges(): iterable
    {
        foreach (UserStatus::cases() as $from) {
            foreach ([UserStatus::PENDING, UserStatus::DISABLED] as $to) {
                yield "$from->value vers $to->value" => [$from, $to];
            }
        }
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function statusesOfAProtectedAccount(): iterable
    {
        foreach (UserStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    public function testTheProtectedAccountIsRecognisedWhateverTheCaseOfItsEmail(): void
    {
        $this->assertTrue(new UserBuilder()->withEmail(User::PROTECTED_EMAIL)->build()->isProtected());
        $this->assertTrue(User::register('id', ' ' . strtoupper(User::PROTECTED_EMAIL), ['ROLE_USER'], 3, $this->now)->isProtected());
        $this->assertFalse(new UserBuilder()->withEmail('autre@example.com')->build()->isProtected());
    }

    public function testOnlyTheProtectedAccountCannotBeDeleted(): void
    {
        // Given
        $ordinary = new UserBuilder()->withEmail('autre@example.com')->build();
        $protected = new UserBuilder()->withEmail(User::PROTECTED_EMAIL)->build();

        // When
        $ordinary->assertCanBeDeleted();

        // Then
        $this->expectExceptionObject(UserException::protectedAccount());
        $protected->assertCanBeDeleted();
    }

    public function testAnAccountNeverSeenIsToBeRecorded(): void
    {
        // Given : un compte actif sans dernière connexion
        $user = new UserBuilder()->build();

        // Then
        $this->assertNull($user->getLastLoginAt());
        $this->assertTrue($user->shouldRecordLogin($this->now));
    }

    #[DataProvider('secondsSinceTheLastLogin')]
    public function testALoginIsRecordedOnlyOncePerInterval(int $seconds, bool $expected): void
    {
        // Given
        $user = new UserBuilder()->withLastLoginAt($this->now->modify("-{$seconds} seconds"))->build();

        // Then
        $this->assertSame($expected, $user->shouldRecordLogin($this->now));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function secondsSinceTheLastLogin(): iterable
    {
        yield 'à l\'instant' => [0, false];
        yield 'il y a 1 seconde' => [1, false];
        yield 'il y a 9 minutes 59' => [599, false];
        yield 'il y a 10 minutes pile' => [600, true];
        yield 'il y a une heure' => [3600, true];
        yield 'il y a un mois' => [30 * 86400, true];
    }

    public function testALastLoginInTheFutureIsNotRecordedAgain(): void
    {
        // Given : horloge qui a reculé entre deux machines, ou date écrite à la main
        $user = new UserBuilder()->withLastLoginAt($this->now->modify('+1 hour'))->build();

        // Then : on n'écrit pas une date plus ancienne par-dessus
        $this->assertFalse($user->shouldRecordLogin($this->now));
    }

    #[DataProvider('statusesThatCannotAuthenticate')]
    public function testAnAccountThatIsNotActiveIsNeverRecorded(UserStatus $status): void
    {
        // Given : même jamais vu
        $user = $this->userWithStatus($status);

        // Then
        $this->assertFalse($user->shouldRecordLogin($this->now));
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function statusesThatCannotAuthenticate(): iterable
    {
        yield 'en attente' => [UserStatus::PENDING];
        yield 'désactivé' => [UserStatus::DISABLED];
    }

    public function testRecordingALoginMovesTheCopyForwardButNeverBack(): void
    {
        // Given
        $user = new UserBuilder()->build();

        // When
        $user->recordLogin($this->now);
        $user->recordLogin($this->now->modify('-1 hour'));

        // Then
        $this->assertEquals($this->now, $user->getLastLoginAt());
        $this->assertFalse($user->shouldRecordLogin($this->now->modify('+5 minutes')));
        $this->assertTrue($user->shouldRecordLogin($this->now->modify('+10 minutes')));
    }

    private function userWithStatus(UserStatus $status, string $email = 'autre@example.com'): User
    {
        $builder = new UserBuilder()->withEmail($email);
        $builder = match ($status) {
            UserStatus::PENDING => $builder->isPending(),
            UserStatus::DISABLED => $builder->isDisabled(),
            UserStatus::ACTIVE => $builder,
        };

        return $builder->build();
    }
}
