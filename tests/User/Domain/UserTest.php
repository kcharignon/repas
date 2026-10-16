<?php

namespace Repas\Tests\User\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;

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
        $user = User::register('id', ' Alexiane@Example.COM ', ['ROLE_USER'], 'hash', 3, $this->now);

        // Then
        $this->assertSame(UserStatus::PENDING, $user->getStatus());
        $this->assertTrue($user->isPending());
        $this->assertFalse($user->isDisabled());
        $this->assertSame('alexiane@example.com', $user->getEmail());
    }

    public function testAnAccountCreatedOutsideRegistrationIsActive(): void
    {
        // When
        $user = User::create('id', 'alexiane@example.com', ['ROLE_USER'], 'hash', 3, $this->now);

        // Then
        $this->assertSame(UserStatus::ACTIVE, $user->getStatus());
        $this->assertFalse($user->isPending());
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
        $this->assertTrue(User::register('id', ' ' . strtoupper(User::PROTECTED_EMAIL), ['ROLE_USER'], 'hash', 3, $this->now)->isProtected());
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
