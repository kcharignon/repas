<?php

namespace Repas\Tests\User\OutputAdapter;


use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Catalog\IngredientCatalogOwnerPostgreSQL;

/**
 * Le propriétaire du catalogue d'ingrédients (ADR 0028, amendement du 2026-10-20) : le compte protégé, ou celui de la variable
 * `INGREDIENT_CATALOG_OWNER_EMAIL` quand elle est renseignée, ou personne. Sur la vraie base ; l'adresse du compte protégé n'est lue que par
 * sa constante, les autres adresses sont des adresses de test.
 */
class IngredientCatalogOwnerPostgreSQLTest extends DatabaseTestCase
{
    private const string OTHER_ADMIN_ID = '22222222-2222-4222-8222-222222222222';
    private const string PROTECTED_ID = '11111111-1111-4111-8111-111111111111';

    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testTheCatalogOwnerIsTheProtectedAccount(): void
    {
        // Given : le compte protégé, un autre administrateur et un utilisateur ordinaire
        $this->saveProtectedAccount();
        $this->userRepository->save(new UserBuilder()->withId(self::OTHER_ADMIN_ID)->withEmail('another-admin@example.com')->isAdmin()->build());
        $this->userRepository->save(new UserBuilder()->withEmail('someone@example.com')->build());

        // When / Then : un seul propriétaire, jamais « tout administrateur »
        $this->assertSame(self::PROTECTED_ID, $this->owner(null)->ownerId());
    }

    public function testABlankVariableMeansNoOverride(): void
    {
        // Given
        $this->saveProtectedAccount();

        // When / Then : la variable est vide dans `.env`, ou faite d'espaces
        $this->assertSame(self::PROTECTED_ID, $this->owner('')->ownerId());
        $this->assertSame(self::PROTECTED_ID, $this->owner('   ')->ownerId());
    }

    public function testTheVariableDesignatesAnotherAccountForDevelopment(): void
    {
        // Given : le compte de démonstration, et le compte protégé qui existe aussi
        $this->saveProtectedAccount();
        $this->userRepository->save(new UserBuilder()->withId(self::OTHER_ADMIN_ID)->withEmail('demo-admin@example.com')->isAdmin()->build());

        // When / Then : la variable l'emporte, quelle que soit la casse ou les espaces (les adresses sont rangées en minuscules)
        $this->assertSame(self::OTHER_ADMIN_ID, $this->owner('demo-admin@example.com')->ownerId());
        $this->assertSame(self::OTHER_ADMIN_ID, $this->owner('  Demo-Admin@Example.com ')->ownerId());
    }

    public function testThereIsNoOwnerWhenTheAccountDoesNotExist(): void
    {
        // Given : ni le compte protégé ni celui de la variable
        $this->userRepository->save(new UserBuilder()->withEmail('someone@example.com')->isAdmin()->build());

        // When / Then : `null`, jamais une erreur ni un autre compte
        $this->assertNull($this->owner(null)->ownerId());
        $this->assertNull($this->owner('missing@example.com')->ownerId());
    }

    public function testTheVariableDoesNotFallBackOnTheProtectedAccount(): void
    {
        // Given : la variable désigne un compte absent alors que le compte protégé existe
        $this->saveProtectedAccount();

        // When / Then : personne (une erreur de configuration ne désigne pas silencieusement un autre propriétaire)
        $this->assertNull($this->owner('missing@example.com')->ownerId());
    }

    public function testTheAnswerIsMemorizedForTheRequestAndForgottenBetweenTwoRequests(): void
    {
        // Given
        $owner = $this->owner(null);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When : le compte n'existe pas encore, on demande deux fois
        $this->assertNull($owner->ownerId());
        $this->assertNull($owner->ownerId());

        // Then : une seule requête, y compris pour une réponse `null`
        $this->assertSame(1, $counter->count());

        // When : le compte naît, mais la requête en cours garde sa réponse
        $this->saveProtectedAccount();
        $this->assertNull($owner->ownerId());

        // Then : la requête suivante (`kernel.reset`) relit
        $owner->reset();
        $this->assertSame(self::PROTECTED_ID, $owner->ownerId());
        $this->assertSame(self::PROTECTED_ID, $owner->ownerId());
    }

    public function testTheServiceIsWiredAndReadsTheVariableOfTheTestEnvironment(): void
    {
        // Given : le propriétaire des tests est désigné par `INGREDIENT_CATALOG_OWNER_EMAIL` de `.env.test` (UserBuilder::isCatalogOwner)
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $this->userRepository->save($owner);
        $this->saveProtectedAccount();

        // When
        $service = static::getContainer()->get(IngredientCatalogOwner::class);

        // Then : le port est bien branché sur l'implémentation de `User`, et la variable désigne le propriétaire, pas le compte protégé
        $this->assertInstanceOf(IngredientCatalogOwnerPostgreSQL::class, $service);
        $this->assertSame($owner->getId(), $service->ownerId());
    }

    private function saveProtectedAccount(): void
    {
        $this->userRepository->save(new UserBuilder()->withId(self::PROTECTED_ID)->withEmail(User::PROTECTED_EMAIL)->isAdmin()->build());
    }

    private function owner(?string $overrideEmail): IngredientCatalogOwnerPostgreSQL
    {
        return new IngredientCatalogOwnerPostgreSQL($this->entityManager, $overrideEmail);
    }
}
