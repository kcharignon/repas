<?php

namespace Repas\Tests\User\OutputAdapter;


use Repas\Shared\Application\Interface\ActiveUserChecker;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Infrastructure\Account\ActiveUserCheckerPostgreSQL;

/**
 * Un compte est actif seulement s'il existe et que son statut est `ACTIVE` (ADR 0021) : le port que la commande vocale (ADR 0029)
 * utilise pour qu'un compte désactivé, en attente ou supprimé ne puisse rien par la voix.
 */
class ActiveUserCheckerTest extends DatabaseTestCase
{
    private UserRepository $users;
    private ActiveUserChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = static::getContainer()->get(UserRepository::class);
        $this->checker = static::getContainer()->get(ActiveUserChecker::class);
    }

    public function testThePortIsWiredOnTheUserImplementation(): void
    {
        $this->assertInstanceOf(ActiveUserCheckerPostgreSQL::class, $this->checker);
    }

    public function testOnlyAnExistingActiveAccountIsActive(): void
    {
        // Given
        $active = new UserBuilder()->build();
        $pending = new UserBuilder()->isPending()->build();
        $disabled = new UserBuilder()->isDisabled()->build();
        foreach ([$active, $pending, $disabled] as $user) {
            $this->users->save($user);
        }

        // Then
        $this->assertTrue($this->checker->isActive($active->getId()));
        $this->assertFalse($this->checker->isActive($pending->getId()));
        $this->assertFalse($this->checker->isActive($disabled->getId()));
        $this->assertFalse($this->checker->isActive('unknown-user'), 'un compte supprimé ou inconnu n\'est pas actif');
    }

    public function testTheAnswerFollowsTheStatusOfTheAccount(): void
    {
        // Given : un compte actif qu'un administrateur désactive ensuite
        $user = new UserBuilder()->build();
        $this->users->save($user);
        $this->assertTrue($this->checker->isActive($user->getId()));

        // When
        $user->changeStatus(UserStatus::DISABLED);
        $this->users->save($user);

        // Then
        $this->assertFalse($this->checker->isActive($user->getId()));
    }
}
