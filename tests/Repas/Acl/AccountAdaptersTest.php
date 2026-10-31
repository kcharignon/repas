<?php

namespace Repas\Tests\Repas\Acl;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Port\DefaultServingCommandPort;
use Repas\Repas\Infrastructure\Acl\AccountAddressQueryAdapter;
use Repas\Repas\Infrastructure\Acl\AccountDirectoryQueryAdapter;
use Repas\Repas\Infrastructure\Acl\CatalogOwnerCommandAdapter;
use Repas\Repas\Infrastructure\Acl\CatalogOwnerQueryAdapter;
use Repas\Repas\Infrastructure\Acl\DefaultServingCommandAdapter;
use Repas\Repas\Infrastructure\Acl\SeedingAccountCommandAdapter;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryLoader\UserInMemoryLoader;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\OpenHostService\AccountAddressQueryOHS;
use Repas\User\Application\OpenHostService\CatalogOwnerCommandOHS;
use Repas\User\Application\OpenHostService\CatalogOwnerQueryOHS;
use Repas\User\Application\OpenHostService\UserAccountCommandOHS;
use Repas\User\Application\OpenHostService\UserDirectoryQueryOHS;

/**
 * La transposition de ce que le contexte `User` publie (comptes, propriétaire du catalogue) en réponses du contexte `Repas` (ADR 0035), avec
 * les vrais Open Host Services branchés sur les doubles en mémoire du contexte `User`. Les handlers de `Repas` exercent aussi ces adapters,
 * par transitivité.
 */
class AccountAdaptersTest extends TestCase
{
    public function testTheDefaultServingIsTheOneOfTheAccountAndTheUsualOneForAnUnknownAccount(): void
    {
        // Given
        $two = new UserBuilder()->withServing(2)->build();
        $six = new UserBuilder()->withServing(6)->build();
        $adapter = new DefaultServingCommandAdapter(new UserAccountCommandOHS(new UserInMemoryRepository([$two, $six])));

        // Then
        $this->assertSame(2, $adapter->of($two->getId()));
        $this->assertSame(6, $adapter->of($six->getId()));
        $this->assertSame(DefaultServingCommandPort::FALLBACK, $adapter->of('unknown-user'));
    }

    public function testTheDefaultServingFollowsTheSettingsOfTheAccount(): void
    {
        // Given
        $user = new UserBuilder()->withServing(4)->build();
        $adapter = new DefaultServingCommandAdapter(new UserAccountCommandOHS(new UserInMemoryRepository([$user])));
        $this->assertSame(4, $adapter->of($user->getId()));

        // When : l'utilisateur change son réglage
        $user->update(5);

        // Then
        $this->assertSame(5, $adapter->of($user->getId()));
    }

    public function testTheSeedingAccountExistsOrNot(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $pending = new UserBuilder()->isPending()->build();
        $adapter = new SeedingAccountCommandAdapter(new UserAccountCommandOHS(new UserInMemoryRepository([$user, $pending])));

        // Then : un compte en attente existe (il est amorcé dès qu'il s'active), un compte inconnu non
        $this->assertTrue($adapter->exists($user->getId()));
        $this->assertTrue($adapter->exists($pending->getId()));
        $this->assertFalse($adapter->exists('unknown-user'));
    }

    public function testTheStatisticsFingerprintMovesWithTheStatisticsAndOnlyThen(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $other = new UserBuilder()->build();
        $adapter = new SeedingAccountCommandAdapter(new UserAccountCommandOHS(new UserInMemoryRepository([$user, $other])));
        $before = $adapter->statisticsFingerprint($user->getId());

        // Then : relue, la même
        $this->assertNotSame('', $before);
        $this->assertSame($before, $adapter->statisticsFingerprint($user->getId()));

        // When : une recette de plus
        $user->createRecipe();

        // Then : une empreinte autre pour ce compte seulement
        $this->assertNotSame($before, $adapter->statisticsFingerprint($user->getId()));
        $this->assertSame('', $adapter->statisticsFingerprint('unknown-user'));
    }

    public function testTheCatalogOwnerIsTransposedOnBothSides(): void
    {
        // Given
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $command = new CatalogOwnerCommandAdapter(new CatalogOwnerCommandOHS(new UserInMemoryRepository([$owner]), UserBuilder::CATALOG_OWNER_EMAIL));
        $query = new CatalogOwnerQueryAdapter(new CatalogOwnerQueryOHS(new UserInMemoryLoader(idByEmail: [$owner->getEmail() => $owner->getId()]), UserBuilder::CATALOG_OWNER_EMAIL));
        $nobody = new CatalogOwnerCommandAdapter(new CatalogOwnerCommandOHS(new UserInMemoryRepository(), UserBuilder::CATALOG_OWNER_EMAIL));

        // Then
        $this->assertSame($owner->getId(), $command->ownerId());
        $this->assertSame($owner->getId(), $query->ownerId());
        $this->assertNull($nobody->ownerId());
    }

    public function testTheDirectoryBecomesTheStatusOfEachAccountInTheSameOrder(): void
    {
        // Given
        $statuses = [['id' => 'a', 'status' => 'ACTIVE'], ['id' => 'b', 'status' => 'PENDING'], ['id' => 'c', 'status' => 'DISABLED']];
        $adapter = new AccountDirectoryQueryAdapter(new UserDirectoryQueryOHS(new UserInMemoryLoader(statuses: $statuses)));

        // Then
        $this->assertSame(['a' => 'ACTIVE', 'b' => 'PENDING', 'c' => 'DISABLED'], $adapter->statusesById());
        $this->assertSame([], new AccountDirectoryQueryAdapter(new UserDirectoryQueryOHS(new UserInMemoryLoader()))->statusesById());
    }

    public function testTheAddressesAreThoseOfTheAskedAccountsOnly(): void
    {
        // Given
        $adapter = new AccountAddressQueryAdapter(new AccountAddressQueryOHS(new UserInMemoryLoader(emailById: ['a' => 'a@example.com', 'b' => 'b@example.com'])));

        // Then
        $this->assertSame(['a' => 'a@example.com'], $adapter->addressesOf(['a', 'unknown']));
        $this->assertSame([], $adapter->addressesOf([]));
    }
}
