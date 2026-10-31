<?php

namespace Repas\Tests\Voice\Acl;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryLoader\UserInMemoryLoader;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\OpenHostService\UserAccountCommandOHS;
use Repas\User\Application\OpenHostService\UserAccountQueryOHS;
use Repas\User\Domain\Model\UserStatus;
use Repas\Voice\Infrastructure\Acl\ActiveUserCommandAdapter;
use Repas\Voice\Infrastructure\Acl\ActiveUserQueryAdapter;

/**
 * La transposition d'un compte du contexte `User` en réponse du contexte `Voice` (ADR 0035), avec les vrais Open Host Services branchés sur
 * les doubles en mémoire du contexte `User`. Les handlers de `Voice` les exercent aussi, par transitivité.
 */
class ActiveUserAdaptersTest extends TestCase
{
    public function testTheCommandSideCallsAnAccountActiveOnlyWhenItsStatusIsActive(): void
    {
        // Given
        $active = new UserBuilder()->build();
        $pending = new UserBuilder()->isPending()->build();
        $disabled = new UserBuilder()->isDisabled()->build();
        $adapter = new ActiveUserCommandAdapter(new UserAccountCommandOHS(new UserInMemoryRepository([$active, $pending, $disabled])));

        // Then
        $this->assertTrue($adapter->isActive($active->getId()));
        $this->assertFalse($adapter->isActive($pending->getId()));
        $this->assertFalse($adapter->isActive($disabled->getId()));
        $this->assertFalse($adapter->isActive('unknown-user'), 'un compte supprimé ou inconnu n\'est pas actif');
    }

    public function testTheCommandSideFollowsTheStatusOfTheAccount(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $adapter = new ActiveUserCommandAdapter(new UserAccountCommandOHS(new UserInMemoryRepository([$user])));
        $this->assertTrue($adapter->isActive($user->getId()));

        // When
        $user->changeStatus(UserStatus::DISABLED);

        // Then
        $this->assertFalse($adapter->isActive($user->getId()));
    }

    public function testTheQuerySideAsksTheLoaderAndTransposesItsAnswer(): void
    {
        // Given
        $adapter = new ActiveUserQueryAdapter(new UserAccountQueryOHS(new UserInMemoryLoader(activeUserIds: ['user-1'])));

        // Then
        $this->assertTrue($adapter->isActive('user-1'));
        $this->assertFalse($adapter->isActive('user-2'));
    }
}
