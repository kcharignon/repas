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

    public function testAnAdministratorCanActivateAPendingAccount(): void
    {
        // Given
        $user = new UserBuilder()->isPending()->build();

        // When
        $user->activate();

        // Then
        $this->assertSame(UserStatus::ACTIVE, $user->getStatus());
    }

    #[DataProvider('statusesThatAreNotPending')]
    public function testOnlyAPendingAccountCanBeActivated(UserBuilder $builder, UserStatus $status): void
    {
        // Given
        $user = $builder->build();

        // Assert
        $this->expectExceptionObject(UserException::invalidStatusTransition($status, UserStatus::ACTIVE));

        // When
        $user->activate();
    }

    /**
     * @return iterable<string, array{UserBuilder, UserStatus}>
     */
    public static function statusesThatAreNotPending(): iterable
    {
        yield 'actif' => [new UserBuilder(), UserStatus::ACTIVE];
        yield 'désactivé' => [new UserBuilder()->isDisabled(), UserStatus::DISABLED];
    }

    #[DataProvider('statusesThatCanBeDisabled')]
    public function testAnActiveOrPendingAccountCanBeDisabled(UserBuilder $builder): void
    {
        // Given
        $user = $builder->build();

        // When
        $user->disable();

        // Then
        $this->assertSame(UserStatus::DISABLED, $user->getStatus());
        $this->assertTrue($user->isDisabled());
    }

    /**
     * @return iterable<string, array{UserBuilder}>
     */
    public static function statusesThatCanBeDisabled(): iterable
    {
        yield 'actif' => [new UserBuilder()];
        yield 'en attente' => [new UserBuilder()->isPending()];
    }

    public function testADisabledAccountCannotBeDisabledAgain(): void
    {
        // Given
        $user = new UserBuilder()->isDisabled()->build();

        // Assert
        $this->expectExceptionObject(UserException::invalidStatusTransition(UserStatus::DISABLED, UserStatus::DISABLED));

        // When
        $user->disable();
    }

    public function testAnInvalidTransitionIsAConflictWithTheCurrentState(): void
    {
        $this->assertSame(409, UserException::invalidStatusTransition(UserStatus::DISABLED, UserStatus::ACTIVE)->getCode());
    }
}
