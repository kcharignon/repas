<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * « Plus de nouveau commun » (ADR 0028, amendement du 2026-10-20, ING-012) de bout en bout : JWT, vrais bus, vraie base. Tout ingrédient créé
 * par l'API est à son créateur, un administrateur compris, et aucun autre compte ne le voit (la passation des anciens communs au propriétaire du
 * catalogue est faite : aucun ingrédient n'est plus sans créateur).
 */
class IngredientNoNewCommonApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        $this->persister->saveUnit(new UnitBuilder()->isGramme()->build());
    }

    public function testAnAdministratorCreatesAnIngredientThatBelongsToHimAndNobodyElseSees(): void
    {
        // Given
        $admin = $this->savedUser(new UserBuilder()->isAdmin());
        $otherAdmin = $this->savedUser(new UserBuilder()->isAdmin());
        $user = $this->savedUser();

        // When
        $this->request('POST', '/api/v1/ingredients', $admin, $this->body('Safran'));

        // Then : créé, à lui, jamais sans créateur
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $slug = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame('safran' . $admin->getId(), $slug);
        $this->assertSame($admin->getId(), $this->ingredients()->findOneBySlug($slug)->getCreatorId());
        $this->assertSame(0, $this->countIngredientsWithoutCreator());
        $this->assertSame(1, static::getContainer()->get(UserRepository::class)->findOneById($admin->getId())->getStatistics()['ingredients']);

        // Then : lui seul le voit et peut le modifier
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $admin);
        $this->assertSame([[$slug, true]], array_map(fn(array $row): array => [$row['slug'], $row['canEdit']], $this->jsonBody()['member']));
        $this->request('GET', '/api/v1/ingredients/search?q=safran', $admin);
        $this->assertSame([$slug], array_column($this->jsonBody()['member'], 'slug'));
        $this->request('GET', '/api/v1/ingredients/' . $slug, $admin);
        $this->assertSame([200, true], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['canEdit']]);

        // Then : ni un utilisateur ordinaire ni un autre administrateur ne le voient dans leur rayon ni dans leur recherche
        foreach ([$user, $otherAdmin] as $viewer) {
            $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $viewer);
            $this->assertSame([], $this->jsonBody()['member']);
            $this->request('GET', '/api/v1/ingredients/search?q=safran', $viewer);
            $this->assertSame([], $this->jsonBody()['member']);
        }
        $this->request('GET', '/api/v1/ingredients/' . $slug, $user);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheCatalogOwnerCreatesHisOwnIngredientAndOnlyHeCanMarkItAsBase(): void
    {
        // Given
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());

        // When
        $this->request('POST', '/api/v1/ingredients', $owner, ['isBase' => true] + $this->body('Safran'));

        // Then : à lui, de base, et l'écran peut proposer le réglage (c'est un ingrédient à lui)
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $slug = basename($this->client->getResponse()->headers->get('Location'));
        $ingredient = $this->ingredients()->findOneBySlug($slug);
        $this->assertSame([$owner->getId(), true], [$ingredient->getCreatorId(), $ingredient->isBase()]);
        $this->request('GET', '/api/v1/ingredients/' . $slug, $owner);
        $this->assertSame([true, true], [$this->jsonBody()['isBase'], $this->jsonBody()['canSetBase']]);
    }

    public function testTheSameNameTwiceForTheSameAccountIsAConflictWhoeverItIs(): void
    {
        // Given
        foreach ([$this->savedUser(), $this->savedUser(new UserBuilder()->isAdmin()), $this->savedUser(new UserBuilder()->isCatalogOwner())] as $account) {
            $this->request('POST', '/api/v1/ingredients', $account, $this->body('Safran'));
            $this->assertSame(201, $this->client->getResponse()->getStatusCode());

            // When : le même nom, ou le même nom une fois normalisé
            foreach (['Safran', 'safran', 'SAFRAN'] as $name) {
                $this->request('POST', '/api/v1/ingredients', $account, $this->body($name));

                // Then
                $this->assertSame(409, $this->client->getResponse()->getStatusCode(), $name);
                $this->assertArrayHasKey('error', $this->jsonBody());
            }
        }
        $this->assertSame(3, $this->countIngredientsNamed('Safran'));
        $this->assertSame(0, $this->countIngredientsNamed('safran') + $this->countIngredientsNamed('SAFRAN'));
    }

    public function testTwoAccountsCreatingTheSameNameHaveTwoDistinctIngredients(): void
    {
        // Given
        $admin = $this->savedUser(new UserBuilder()->isAdmin());
        $user = $this->savedUser();

        // When
        $this->request('POST', '/api/v1/ingredients', $admin, $this->body('Safran'));
        $adminSlug = basename($this->client->getResponse()->headers->get('Location'));
        $this->request('POST', '/api/v1/ingredients', $user, $this->body('Safran'));

        // Then : aucun conflit entre comptes
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $userSlug = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertNotSame($adminSlug, $userSlug);
        $this->assertSame([$admin->getId(), $user->getId()], [$this->ingredients()->findOneBySlug($adminSlug)->getCreatorId(), $this->ingredients()->findOneBySlug($userSlug)->getCreatorId()]);
    }

    public function testAnOrdinaryUserCreatesExactlyWhatHeCreatedBefore(): void
    {
        // Given
        $user = $this->savedUser();

        // When
        $this->request('POST', '/api/v1/ingredients', $user, $this->body("Praliné d'été"));

        // Then : même slug (nom et identifiant), même créateur, même compteur
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('/api/v1/ingredients/praline-d-ete' . $user->getId(), $this->client->getResponse()->headers->get('Location'));
        $this->assertSame($user->getId(), $this->ingredients()->findOneBySlug('praline-d-ete' . $user->getId())->getCreatorId());
        $this->assertSame(1, static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getStatistics()['ingredients']);
    }

    public function testAfterTheHandoverTheOwnerCannotCreateAgainAnIngredientHeAlreadyHas(): void
    {
        // Given : après la passation, l'ancien commun « Tomate » (slug sans identifiant de compte) est à son propriétaire
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $this->savedIngredient($owner, 'Tomate');

        // When
        $this->request('POST', '/api/v1/ingredients', $owner, $this->body('Tomate'));

        // Then : 409 alors que le slug `tomate<identifiant>` est libre, rien n'est créé
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame(1, $this->countIngredientsNamed('Tomate'));
        $this->assertFalse($this->ingredients()->exists('tomate' . $owner->getId()));
    }

    public function testAnOldCommonOfTheOwnerDoesNotBlockAnotherAccountFromCreatingTheSameName(): void
    {
        // Given
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $this->savedIngredient($owner, 'Tomate');
        $admin = $this->savedUser(new UserBuilder()->isAdmin());

        // When
        $this->request('POST', '/api/v1/ingredients', $admin, $this->body('Tomate'));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame($admin->getId(), $this->ingredients()->findOneBySlug('tomate' . $admin->getId())->getCreatorId());
    }

    // ---

    /**
     * @return array<string, mixed>
     */
    private function body(string $name): array
    {
        return ['name' => $name, 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme'];
    }

    private function savedIngredient(?User $creator, string $name): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isBakery())->withCreator($creator)->withSlug(StringTool::slugify($name))
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function savedUser(UserBuilder $builder = new UserBuilder()): User
    {
        $user = $builder->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function ingredients(): IngredientRepository
    {
        $this->entityManager->clear();

        return static::getContainer()->get(IngredientRepository::class);
    }

    private function countIngredientsWithoutCreator(): int
    {
        return (int) $this->entityManager->createQueryBuilder()->select('COUNT(i.slug)')->from(IngredientEntity::class, 'i')
            ->where('i.creatorId IS NULL')->getQuery()->getSingleScalarResult();
    }

    private function countIngredientsNamed(string $name): int
    {
        return (int) $this->entityManager->createQueryBuilder()->select('COUNT(i.slug)')->from(IngredientEntity::class, 'i')
            ->where('i.name = :name')->setParameter('name', $name)->getQuery()->getSingleScalarResult();
    }
}
