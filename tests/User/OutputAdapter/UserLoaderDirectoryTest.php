<?php

namespace Repas\Tests\User\OutputAdapter;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\OpenHostService\AccountAddressQueryOHS;
use Repas\User\Application\OpenHostService\CatalogOwnerCommandOHS;
use Repas\User\Application\OpenHostService\CatalogOwnerQueryOHS;
use Repas\User\Application\OpenHostService\UserDirectoryQueryOHS;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;

/**
 * Ce que le contexte `User` publie de ses comptes aux rapports d'exploitation et aux écritures d'ingrédients d'un autre contexte (ADR 0035),
 * sur la vraie base : l'identifiant d'une adresse (le propriétaire du catalogue), le statut de chaque compte, l'adresse de comptes donnés.
 */
class UserLoaderDirectoryTest extends DatabaseTestCase
{
    private const string OTHER_ADMIN_ID = '22222222-2222-4222-8222-222222222222';
    private const string PROTECTED_ID = '11111111-1111-4111-8111-111111111111';

    private UserRepository $users;
    private UserLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = static::getContainer()->get(UserRepository::class);
        $this->loader = static::getContainer()->get(UserLoader::class);
    }

    public function testTheIdOfAnAddressIsFoundWhateverItsCase(): void
    {
        // Given
        $user = new UserBuilder()->withEmail('someone@example.com')->build();
        $this->users->save($user);

        // Then : l'adresse est rangée en minuscules ; une adresse inconnue n'est personne
        $this->assertSame($user->getId(), $this->loader->idOfEmail('someone@example.com'));
        $this->assertSame($user->getId(), $this->loader->idOfEmail('  Someone@Example.com '));
        $this->assertNull($this->loader->idOfEmail('nobody@example.com'));
    }

    public function testEveryAccountIsListedWithItsStatusInTheOrderOfItsIdentifier(): void
    {
        // Given : trois comptes, dans le désordre
        $disabled = new UserBuilder()->withId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->withEmail('c@example.com')->isDisabled()->build();
        $active = new UserBuilder()->withId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')->withEmail('a@example.com')->build();
        $pending = new UserBuilder()->withId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')->withEmail('b@example.com')->isPending()->build();
        foreach ([$disabled, $active, $pending] as $user) {
            $this->users->save($user);
        }

        // When
        $statuses = $this->loader->listStatuses();

        // Then
        $this->assertSame([
            ['id' => $active->getId(), 'status' => 'ACTIVE'],
            ['id' => $pending->getId(), 'status' => 'PENDING'],
            ['id' => $disabled->getId(), 'status' => 'DISABLED'],
        ], $statuses);
    }

    public function testTheStatusFollowsTheAccount(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $this->users->save($user);
        $this->assertSame([['id' => $user->getId(), 'status' => 'ACTIVE']], $this->loader->listStatuses());

        // When
        $user->changeStatus(UserStatus::DISABLED);
        $this->users->save($user);

        // Then
        $this->assertSame([['id' => $user->getId(), 'status' => 'DISABLED']], $this->loader->listStatuses());
    }

    public function testTheAddressesOfTheAskedAccountsOnlyAreRead(): void
    {
        // Given
        $first = new UserBuilder()->withEmail('first@example.com')->build();
        $second = new UserBuilder()->withEmail('second@example.com')->build();
        $third = new UserBuilder()->withEmail('third@example.com')->build();
        foreach ([$first, $second, $third] as $user) {
            $this->users->save($user);
        }

        // When
        $addresses = $this->loader->emailsOf([$first->getId(), $third->getId(), 'unknown-user']);

        // Then : les deux demandés, jamais le troisième ni l'inconnu ; l'ordre n'est pas garanti (identifiants aléatoires), il ne se compare donc pas
        $expected = [$first->getId() => 'first@example.com', $third->getId() => 'third@example.com'];
        ksort($addresses);
        ksort($expected);
        $this->assertSame($expected, $addresses);
        $this->assertSame([], $this->loader->emailsOf([]));
    }

    public function testTheOpenHostServicesAreWiredOnTheLoaderAndTheRepository(): void
    {
        // Given
        $first = new UserBuilder()->withEmail('first@example.com')->build();
        $this->users->save($first);

        // Then
        $container = static::getContainer();
        $this->assertSame([$first->getId() => 'first@example.com'], $container->get(AccountAddressQueryOHS::class)->addressesOf([$first->getId()]));
        $this->assertSame([$first->getId()], array_map(static fn($entry): string => $entry->id, $container->get(UserDirectoryQueryOHS::class)->accounts()));
    }

    public function testTheCatalogOwnerOfBothSidesIsTheAccountOfTheVariableOfTheTestEnvironment(): void
    {
        // Given : le propriétaire des tests est désigné par `INGREDIENT_CATALOG_OWNER_EMAIL` de `.env.test` (UserBuilder::isCatalogOwner),
        // et le compte protégé existe aussi
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $this->users->save($owner);
        $this->users->save(new UserBuilder()->withId(self::PROTECTED_ID)->withEmail(User::PROTECTED_EMAIL)->isAdmin()->build());
        $this->users->save(new UserBuilder()->withId(self::OTHER_ADMIN_ID)->withEmail('another-admin@example.com')->isAdmin()->build());

        // When
        $container = static::getContainer();

        // Then : la variable désigne le propriétaire, pas le compte protégé ; les deux côtés disent la même chose
        $this->assertSame($owner->getId(), $container->get(CatalogOwnerCommandOHS::class)->ownerId());
        $this->assertSame($owner->getId(), $container->get(CatalogOwnerQueryOHS::class)->ownerId());
    }
}
