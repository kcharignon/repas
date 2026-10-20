<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
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
 * Le marqueur « ingrédient de base » (ADR 0028, amendement du 2026-10-20, ING-011) de bout en bout : JWT, vrais bus, vraie base, vrai
 * propriétaire du catalogue (le compte de `INGREDIENT_CATALOG_OWNER_EMAIL` de `.env.test`, `UserBuilder::isCatalogOwner()`). Seul ce
 * compte pose le marqueur ; tout autre reçoit `403` `INGREDIENT_BASE_FORBIDDEN` et rien n'est écrit, un administrateur compris.
 */
class IngredientBaseMarkerApiFlowTest extends DatabaseTestCase
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

    public function testTheCatalogOwnerMarksHisIngredientAndEveryReadSaysSo(): void
    {
        // Given
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $ingredient = $this->savedIngredient($owner);

        // When
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner, ['isBase' => true] + $this->body('Farine complète'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->ingredients()->findOneBySlug($ingredient->getSlug())->isBase());
        $this->request('GET', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner);
        $fiche = $this->jsonBody();
        $this->assertSame([true, true, true, 'Farine complète'], [$fiche['isBase'], $fiche['canSetBase'], $fiche['canEdit'], $fiche['name']]);
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $owner);
        $this->assertSame([['slug' => $ingredient->getSlug(), 'name' => 'Farine complète', 'purchaseUnitSymbol' => 'g', 'canEdit' => true, 'isBase' => true]], $this->jsonBody()['member']);
    }

    public function testTheCatalogOwnerRemovesTheMarkerAndAnOldClientNeverResetsIt(): void
    {
        // Given : un ingrédient de base
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $ingredient = $this->savedIngredient($owner, isBase: true);

        // When : un ancien client renvoie le formulaire sans le champ, ou avec `null`
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner, $this->body('Farine complète'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner, ['isBase' => null] + $this->body('Farine de blé'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : le marqueur n'a pas bougé
        $this->assertTrue($this->ingredients()->findOneBySlug($ingredient->getSlug())->isBase());
        $this->assertSame('Farine de blé', $this->ingredients()->findOneBySlug($ingredient->getSlug())->getName());

        // When : le propriétaire le retire
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner, ['isBase' => false] + $this->body('Farine de blé'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->ingredients()->findOneBySlug($ingredient->getSlug())->isBase());
    }

    public function testAnOrdinaryUserCannotMarkHisIngredientAndNothingIsWritten(): void
    {
        // Given
        $user = $this->savedUser();
        $ingredient = $this->savedIngredient($user);

        // When
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $user, ['isBase' => true] + $this->body('Farine complète'));

        // Then : 403 avec le code, ni le marqueur ni le reste de la modification
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => IngredientException::INGREDIENT_BASE_FORBIDDEN]], $this->jsonBody());
        $unchanged = $this->ingredients()->findOneBySlug($ingredient->getSlug());
        $this->assertSame([false, 'farine'], [$unchanged->isBase(), $unchanged->getName()]);
    }

    public function testAnOrdinaryUserMayRepeatTheCurrentValueAndSeesNothingToSet(): void
    {
        // Given : un propriétaire du catalogue existe, un utilisateur ordinaire a son ingrédient
        $this->savedUser(new UserBuilder()->isCatalogOwner());
        $user = $this->savedUser();
        $ingredient = $this->savedIngredient($user);

        // When : il renvoie la valeur qu'il a lue (faux), puis lit la fiche et le rayon
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $user, ['isBase' => false] + $this->body('Farine complète'));
        $put = $this->client->getResponse()->getStatusCode();
        $this->request('GET', '/api/v1/ingredients/' . $ingredient->getSlug(), $user);
        $fiche = $this->jsonBody();
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $user);

        // Then
        $this->assertSame(204, $put);
        $this->assertSame([false, false, true], [$fiche['isBase'], $fiche['canSetBase'], $fiche['canEdit']]);
        $this->assertSame([false], array_column($this->jsonBody()['member'], 'isBase'));
    }

    public function testAnAdministratorWhoIsNotTheCatalogOwnerCannotMarkAnythingEither(): void
    {
        // Given : un autre administrateur, l'ingrédient d'un utilisateur et un ancien commun (un administrateur les modifie, passe-droit)
        $this->savedUser(new UserBuilder()->isCatalogOwner());
        $admin = $this->savedUser(new UserBuilder()->isAdmin());
        $theirs = $this->savedIngredient($this->savedUser());
        $common = $this->savedIngredient(null, name: 'riz');

        // When / Then : il peut modifier (comme avant), pas marquer
        foreach ([$theirs, $common] as $ingredient) {
            $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $admin, ['isBase' => true] + $this->body('Nom changé'));
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $ingredient->getSlug());
            $this->assertSame(['error' => ['message' => IngredientException::INGREDIENT_BASE_FORBIDDEN]], $this->jsonBody());
            $this->assertFalse($this->ingredients()->findOneBySlug($ingredient->getSlug())->isBase());
            $this->assertNotSame('Nom changé', $this->ingredients()->findOneBySlug($ingredient->getSlug())->getName());
        }
        $this->request('PUT', '/api/v1/ingredients/' . $common->getSlug(), $admin, $this->body('Riz complet'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/ingredients/' . $theirs->getSlug(), $admin);
        $this->assertSame([false, false], [$this->jsonBody()['isBase'], $this->jsonBody()['canSetBase']]);
    }

    public function testTheCatalogOwnerMarksAnOldCommonIngredientButTheFicheDoesNotOfferItYet(): void
    {
        // Given : avant la passation, les anciens communs n'ont pas de créateur
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $common = $this->savedIngredient(null, name: 'riz');

        // When
        $this->request('PUT', '/api/v1/ingredients/' . $common->getSlug(), $owner, ['isBase' => true] + $this->body('Riz'));

        // Then : le back l'accepte, la fiche ne propose le réglage que sur un ingrédient à lui
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/ingredients/' . $common->getSlug(), $owner);
        $this->assertSame([true, false], [$this->jsonBody()['isBase'], $this->jsonBody()['canSetBase']]);
        $this->request('GET', '/api/v1/ingredients/' . $common->getSlug(), $this->savedUser());
        $this->assertSame([false, false], [$this->jsonBody()['isBase'], $this->jsonBody()['canSetBase']]);
    }

    public function testNobodyMarksAnythingWhenTheCatalogOwnerAccountDoesNotExist(): void
    {
        // Given : base de développement neuve, ni compte protégé ni compte de la variable ; un administrateur et son ingrédient
        $admin = $this->savedUser(new UserBuilder()->isAdmin());
        $ingredient = $this->savedIngredient($admin);

        // When
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $admin, ['isBase' => true] + $this->body('Farine complète'));

        // Then : un refus, jamais une erreur
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => IngredientException::INGREDIENT_BASE_FORBIDDEN]], $this->jsonBody());
        $this->request('GET', '/api/v1/ingredients/' . $ingredient->getSlug(), $admin);
        $this->assertSame([false, false], [$this->jsonBody()['isBase'], $this->jsonBody()['canSetBase']]);
    }

    public function testOnlyTheCatalogOwnerCreatesABaseIngredient(): void
    {
        // Given
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $admin = $this->savedUser(new UserBuilder()->isAdmin());
        $user = $this->savedUser();

        // When : le propriétaire crée un ingrédient de base
        $this->request('POST', '/api/v1/ingredients', $owner, ['isBase' => true] + $this->body('Praliné'));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->ingredients()->findOneBySlug(basename($this->client->getResponse()->headers->get('Location')))->isBase());

        // When : l'administrateur et l'utilisateur ordinaire essaient
        foreach ([$admin, $user] as $forbidden) {
            $this->request('POST', '/api/v1/ingredients', $forbidden, ['isBase' => true] + $this->body('Cacao'));

            // Then : 403, rien n'est créé
            $this->assertSame(403, $this->client->getResponse()->getStatusCode());
            $this->assertSame(['error' => ['message' => IngredientException::INGREDIENT_BASE_FORBIDDEN]], $this->jsonBody());
            $this->assertNull($this->client->getResponse()->headers->get('Location'));
        }
        $this->assertSame(0, $this->countIngredientsNamed('Cacao'));

        // When : sans le champ, ou avec `false`, tout le monde crée comme avant
        $this->request('POST', '/api/v1/ingredients', $user, $this->body('Cacao'));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/ingredients', $user, ['isBase' => false] + $this->body('Vanille'));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->ingredients()->findOneBySlug(basename($this->client->getResponse()->headers->get('Location')))->isBase());
    }

    public function testTheMarkerOfOneAccountIsNeverSeenByAnother(): void
    {
        // Given : le propriétaire a un ingrédient de base, un utilisateur ordinaire a le sien
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $user = $this->savedUser();
        $base = $this->savedIngredient($owner, isBase: true);
        $this->savedIngredient($user);

        // When / Then : l'utilisateur ne voit ni l'ingrédient du propriétaire (404, comme avant), ni un marqueur dans son rayon
        $this->request('GET', '/api/v1/ingredients/' . $base->getSlug(), $user);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $user);
        $this->assertSame([false], array_column($this->jsonBody()['member'], 'isBase'));
        $this->request('GET', '/api/v1/departments/boulangerie/ingredients', $owner);
        $this->assertSame([true], array_column($this->jsonBody()['member'], 'isBase'));
    }

    // ---

    /**
     * @return array<string, mixed>
     */
    private function body(string $name): array
    {
        return ['name' => $name, 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme'];
    }

    private function savedIngredient(?User $creator, bool $isBase = false, string $name = 'farine'): Ingredient
    {
        $builder = new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isBakery())->withCreator($creator, true)->withBase($isBase)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme());
        $ingredient = $builder->build();
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

    private function countIngredientsNamed(string $name): int
    {
        return (int) $this->entityManager->createQueryBuilder()->select('COUNT(i.slug)')->from(IngredientEntity::class, 'i')
            ->where('i.name = :name')->setParameter('name', $name)->getQuery()->getSingleScalarResult();
    }
}
