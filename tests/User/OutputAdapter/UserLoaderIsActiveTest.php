<?php

namespace Repas\Tests\User\OutputAdapter;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Application\Loader\UserLoader;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\UserStatus;

/**
 * Un compte est actif seulement s'il existe et que son statut est `ACTIVE` (ADR 0021) : ce que le contexte `Voice` apprend par l'Open
 * Host Service `UserAccountQueryOHS` (ADR 0035) pour qu'un compte désactivé, en attente ou supprimé ne puisse rien par la voix.
 */
class UserLoaderIsActiveTest extends DatabaseTestCase
{
    private UserRepository $users;
    private UserLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = static::getContainer()->get(UserRepository::class);
        $this->loader = static::getContainer()->get(UserLoader::class);
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
        $this->assertTrue($this->loader->isActive($active->getId()));
        $this->assertFalse($this->loader->isActive($pending->getId()));
        $this->assertFalse($this->loader->isActive($disabled->getId()));
        $this->assertFalse($this->loader->isActive('unknown-user'), 'un compte supprimé ou inconnu n\'est pas actif');
    }

    public function testTheAnswerFollowsTheStatusOfTheAccount(): void
    {
        // Given : un compte actif qu'un administrateur désactive ensuite
        $user = new UserBuilder()->build();
        $this->users->save($user);
        $this->assertTrue($this->loader->isActive($user->getId()));

        // When
        $user->changeStatus(UserStatus::DISABLED);
        $this->users->save($user);

        // Then
        $this->assertFalse($this->loader->isActive($user->getId()));
    }
}
