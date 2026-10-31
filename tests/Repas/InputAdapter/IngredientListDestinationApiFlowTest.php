<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * La destination des produits (ADR 0036, LST-003) de bout en bout, avec les vrais bus, la vraie base et la vraie sécurité : régler un produit
 * à part, le relire sur sa fiche, voir le type de chaque rayon, et le résultat de la règle sur un compte qui a un rayon typé, un produit à
 * part et un produit « liste principale ». Un compte sans type ne voit rien changer.
 */
class IngredientListDestinationApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private User $other;
    private User $admin;
    private Ingredient $steak;
    private Ingredient $sausage;
    private Ingredient $cheese;
    private Ingredient $salt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->owner = $this->savedUser();
        $this->other = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);
        // Deux produits dans le rayon de la viande, un dans celui du fromage, un dans « divers »
        $this->steak = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat());
        $this->sausage = $this->savedIngredient('Saucisse', new DepartmentBuilder()->isMeat());
        $this->cheese = $this->savedIngredient('Fromage râpé', new DepartmentBuilder()->isCheese());
        $this->salt = $this->savedIngredient('Sel', new DepartmentBuilder()->isMiscellaneous());
    }

    public function testAnAccountWithoutTypeSeesNothingChange(): void
    {
        // When
        $this->request('GET', '/api/v1/ingredients/' . $this->steak->getSlug(), $this->owner);
        $ingredient = $this->jsonBody();
        $this->request('GET', '/api/v1/departments', $this->owner);
        $departments = $this->jsonBody()['member'];

        // Then : la clé est toujours écrite, « comme son rayon » ; aucun rayon n'a de type ; tout va dans la liste principale
        $this->assertSame(['mode' => 'DEFAULT', 'typeId' => null, 'typeName' => null], $ingredient['listDestination']);
        $this->assertSame(['divers' => null, 'fromage' => null, 'viande' => null], $this->listTypeByDepartment($departments));
        $this->assertSame(
            [$this->steak->getSlug() => null, $this->sausage->getSlug() => null, $this->cheese->getSlug() => null, $this->salt->getSlug() => null],
            $this->destinations($this->owner, [$this->steak, $this->sausage, $this->cheese, $this->salt]),
        );
    }

    public function testThePrioritiesOnAnAccountWithATypedDepartmentAnApartProductAndAMainListProduct(): void
    {
        // Given : « Boucherie » tient le rayon de la viande, « Marché » ne tient rien
        $butcher = $this->createdType($this->owner, 'Boucherie', ['viande']);
        $market = $this->createdType($this->owner, 'Marché', []);

        // When : la saucisse va dans la liste principale, le fromage (rayon sans type) à « Marché »
        $this->put($this->sausage, 'main', $this->owner);
        $sausagePut = $this->client->getResponse();
        $this->put($this->cheese, $market, $this->owner);
        $cheesePut = $this->client->getResponse();

        // Then : 204 sans corps
        $this->assertSame([204, ''], [$sausagePut->getStatusCode(), $sausagePut->getContent()]);
        $this->assertSame([204, ''], [$cheesePut->getStatusCode(), $cheesePut->getContent()]);
        $this->assertSame(
            [
                $this->steak->getSlug() => $butcher,    // aucun choix : le type de son rayon
                $this->sausage->getSlug() => null,      // « liste principale » choisie, malgré le type de son rayon
                $this->cheese->getSlug() => $market,    // le choix du produit, rayon sans type
                $this->salt->getSlug() => null,         // aucun choix, rayon sans type : la liste principale
            ],
            $this->destinations($this->owner, [$this->steak, $this->sausage, $this->cheese, $this->salt]),
        );
        // Le détail de chaque fiche, et le type de chaque rayon (propre à ce compte)
        $this->request('GET', '/api/v1/ingredients/' . $this->steak->getSlug(), $this->owner);
        $this->assertSame(['mode' => 'DEFAULT', 'typeId' => null, 'typeName' => null], $this->jsonBody()['listDestination']);
        $this->request('GET', '/api/v1/ingredients/' . $this->sausage->getSlug(), $this->owner);
        $this->assertSame(['mode' => 'MAIN', 'typeId' => null, 'typeName' => null], $this->jsonBody()['listDestination']);
        $this->request('GET', '/api/v1/ingredients/' . $this->cheese->getSlug(), $this->owner);
        $this->assertSame(['mode' => 'TYPE', 'typeId' => $market, 'typeName' => 'Marché'], $this->jsonBody()['listDestination']);
        $this->request('GET', '/api/v1/departments', $this->owner);
        $this->assertSame(['divers' => null, 'fromage' => null, 'viande' => $butcher], $this->listTypeByDepartment($this->jsonBody()['member']));
        $this->request('GET', '/api/v1/departments', $this->other);
        $this->assertSame(['divers' => null, 'fromage' => null, 'viande' => null], $this->listTypeByDepartment($this->jsonBody()['member']));
        // Le type compte maintenant un produit à part
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $this->assertSame(['Boucherie' => 0, 'Marché' => 1], $this->countsByType());
    }

    public function testDefaultGivesTheProductBackToItsDepartmentAndAChoiceCanBeChanged(): void
    {
        // Given
        $butcher = $this->createdType($this->owner, 'Boucherie', ['viande']);
        $market = $this->createdType($this->owner, 'Marché', []);
        $this->put($this->steak, $market, $this->owner);
        $this->assertSame($market, $this->destinations($this->owner, [$this->steak])[$this->steak->getSlug()]);

        // When : un autre type, puis la liste principale, puis « comme son rayon »
        $this->put($this->steak, $butcher, $this->owner);
        $toButcher = $this->destinations($this->owner, [$this->steak])[$this->steak->getSlug()];
        $this->put($this->steak, 'main', $this->owner);
        $toMain = $this->destinations($this->owner, [$this->steak])[$this->steak->getSlug()];
        $this->put($this->steak, 'default', $this->owner);
        $backToDefault = $this->destinations($this->owner, [$this->steak])[$this->steak->getSlug()];

        // Then
        $this->assertSame([$butcher, null, $butcher], [$toButcher, $toMain, $backToDefault]);
        $this->request('GET', '/api/v1/ingredients/' . $this->steak->getSlug(), $this->owner);
        $this->assertSame('DEFAULT', $this->jsonBody()['listDestination']['mode']);
        $this->assertNull($this->storedDestination($this->steak));
    }

    public function testTheChoiceOfATypeThatIsDeletedFallsBackWithoutAnyError(): void
    {
        // Given : le steak est à part dans « Marché », qui tient aussi le rayon de la viande ; on supprime « Marché »
        $market = $this->createdType($this->owner, 'Marché', ['viande']);
        $this->put($this->steak, $market, $this->owner);
        $this->request('DELETE', '/api/v1/shopping-list-types/' . $market, $this->owner);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When / Then : plus de choix, plus de type de rayon
        $this->request('GET', '/api/v1/ingredients/' . $this->steak->getSlug(), $this->owner);
        $this->assertSame(['mode' => 'DEFAULT', 'typeId' => null, 'typeName' => null], $this->jsonBody()['listDestination']);
        $this->assertSame([$this->steak->getSlug() => null], $this->destinations($this->owner, [$this->steak]));

        // And : même un choix périmé resté en base se lit sans erreur, comme « comme son rayon »
        $this->entityManager->getConnection()->executeStatement('UPDATE ingredient SET list_destination = ? WHERE slug = ?', [$market, $this->steak->getSlug()]);
        $this->request('GET', '/api/v1/ingredients/' . $this->steak->getSlug(), $this->owner);
        $this->assertSame(['mode' => 'DEFAULT', 'typeId' => null, 'typeName' => null], $this->jsonBody()['listDestination']);
        $this->assertSame([$this->steak->getSlug() => null], $this->destinations($this->owner, [$this->steak]));
    }

    public function testATypeOfAnotherAccountIsNotFoundAndNothingIsWritten(): void
    {
        // Given
        $theirs = $this->createdType($this->other, 'Boucherie', ['viande']);
        $unknown = '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34';

        // When / Then : le même refus pour le type d'un autre et pour un type inconnu, y compris pour un administrateur propriétaire du produit
        foreach ([$theirs, $unknown, 'pas-un-identifiant'] as $destination) {
            $this->put($this->steak, $destination, $this->owner);
            $this->assertSame([404, ['error' => ['message' => 'LIST_TYPE_NOT_FOUND']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()], $destination);
            $this->assertNull($this->storedDestination($this->steak));
        }
        $adminsSteak = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), $this->admin);
        $this->put($adminsSteak, $theirs, $this->admin);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->storedDestination($adminsSteak));
    }

    public function testTheProductOfAnotherAccountIsForbiddenAdministratorIncluded(): void
    {
        // Given
        $mine = $this->createdType($this->owner, 'Boucherie', ['viande']);

        foreach ([$this->other, $this->admin] as $caller) {
            // When
            $this->put($this->steak, 'main', $caller);

            // Then : pas de passe-droit
            $this->assertSame(403, $this->client->getResponse()->getStatusCode());
            $this->assertNull($this->storedDestination($this->steak));
        }
        $this->put($this->steak, $mine, $this->other);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->storedDestination($this->steak));
        // Un produit inconnu est introuvable
        $this->put('inconnu', 'main', $this->owner);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRefusesAnInvalidBodyAndAnUnauthenticatedRequest(): void
    {
        // When / Then
        foreach ([[], ['destination' => ''], ['destination' => null], ['destination' => 3], ['destination' => str_repeat('a', 37)]] as $body) {
            $this->request('PUT', '/api/v1/ingredients/' . $this->steak->getSlug() . '/list-destination', $this->owner, $body);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($body));
        }
        $this->assertNull($this->storedDestination($this->steak));
        $this->client->request('PUT', '/api/v1/ingredients/' . $this->steak->getSlug() . '/list-destination', content: '{"destination":"main"}', server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testTheChoiceOfOneAccountDoesNotLeakToTheOtherAccountsView(): void
    {
        // Given : le steak est à part chez le propriétaire ; l'autre compte a le sien
        $market = $this->createdType($this->owner, 'Marché', []);
        $this->put($this->steak, $market, $this->owner);
        $theirSteak = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), $this->other);

        // When
        $this->request('GET', '/api/v1/ingredients/' . $theirSteak->getSlug(), $this->other);

        // Then
        $this->assertSame(['mode' => 'DEFAULT', 'typeId' => null, 'typeName' => null], $this->jsonBody()['listDestination']);
        $this->assertSame([$theirSteak->getSlug() => null], $this->destinations($this->other, [$theirSteak]));
    }

    public function testMergingKeepsTheChoiceOfTheKeptProductAndDroppingTheAbsorbedOne(): void
    {
        // Given : la saucisse (gardée) est dans « Boucherie », le steak (absorbé) dans « Marché »
        $butcher = $this->createdType($this->owner, 'Boucherie', []);
        $market = $this->createdType($this->owner, 'Marché', []);
        $this->put($this->sausage, $butcher, $this->owner);
        $this->put($this->steak, $market, $this->owner);

        // When
        $this->request('POST', '/api/v1/ingredients/' . $this->steak->getSlug() . '/merge', $this->owner, ['into' => $this->sausage->getSlug()]);

        // Then : le produit conservé garde SON choix, le type du produit absorbé n'a plus de produit à part
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame($butcher, $this->storedDestination($this->sausage));
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $this->assertSame(['Boucherie' => 1, 'Marché' => 0], $this->countsByType());
    }

    public function testMergingKeepsAMainChoiceOfTheKeptProductEvenWhenTheAbsorbedOneHadAType(): void
    {
        // Given
        $market = $this->createdType($this->owner, 'Marché', []);
        $this->put($this->sausage, 'main', $this->owner);
        $this->put($this->steak, $market, $this->owner);

        // When
        $this->request('POST', '/api/v1/ingredients/' . $this->steak->getSlug() . '/merge', $this->owner, ['into' => $this->sausage->getSlug()]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('main', $this->storedDestination($this->sausage));
    }

    public function testRemovingAProductMakesItLeaveTheCountOfItsTypeAndTouchesNothingElse(): void
    {
        // Given : deux produits à part dans « Marché », dont un seul sera supprimé
        $market = $this->createdType($this->owner, 'Marché', []);
        $this->put($this->salt, $market, $this->owner);
        $this->put($this->cheese, $market, $this->owner);
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $this->assertSame(2, $this->jsonBody()['member'][0]['ingredientCount']);

        // When
        $this->request('DELETE', '/api/v1/ingredients/' . $this->salt->getSlug(), $this->owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $this->assertSame(1, $this->jsonBody()['member'][0]['ingredientCount']);
        $this->request('GET', '/api/v1/shopping-list-types/' . $market, $this->owner);
        $this->assertSame([$this->cheese->getSlug()], array_column($this->jsonBody()['ingredients'], 'slug'));
        $this->assertSame($market, $this->storedDestination($this->cheese));
    }

    /**
     * Le nombre de produits à part de chaque type, tel que la liste des types le dit (deux types créés dans la même seconde n'ont pas d'ordre
     * garanti entre eux : on compare par nom).
     *
     * @return array<string, int>
     */
    private function countsByType(): array
    {
        $counts = array_column($this->jsonBody()['member'], 'ingredientCount', 'name');
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<string> $departmentSlugs
     */
    private function createdType(User $user, string $name, array $departmentSlugs): string
    {
        $this->request('POST', '/api/v1/shopping-list-types', $user, ['name' => $name]);
        $id = basename($this->client->getResponse()->headers->get('Location'));
        if ($departmentSlugs !== []) {
            $this->request('PUT', '/api/v1/shopping-list-types/' . $id, $user, ['name' => $name, 'departmentSlugs' => $departmentSlugs]);
            $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        }

        return $id;
    }

    /**
     * @param Ingredient|string $ingredient
     */
    private function put(Ingredient|string $ingredient, string $destination, User $user): void
    {
        $slug = $ingredient instanceof Ingredient ? $ingredient->getSlug() : $ingredient;
        $this->request('PUT', '/api/v1/ingredients/' . $slug . '/list-destination', $user, ['destination' => $destination]);
    }

    /**
     * @param list<Ingredient> $ingredients
     *
     * @return array<string, string|null> la règle de l'ADR, lue sur la vraie base
     */
    private function destinations(User $user, array $ingredients): array
    {
        $this->entityManager->clear();

        return static::getContainer()->get(ListRoutingRepository::class)->destinationsFor($user->getId(), array_map(static fn(Ingredient $i): string => $i->getSlug(), $ingredients));
    }

    private function storedDestination(Ingredient $ingredient): ?string
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT list_destination FROM ingredient WHERE slug = ?', [$ingredient->getSlug()]);

        return $value === false ? null : $value;
    }

    /**
     * @param list<array<string, mixed>> $departments
     *
     * @return array<string, string|null>
     */
    private function listTypeByDepartment(array $departments): array
    {
        $byDepartment = array_column($departments, 'listTypeId', 'slug');
        ksort($byDepartment);

        return $byDepartment;
    }

    private function savedIngredient(string $name, DepartmentBuilder $department, ?User $creator = null): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDepartment($department)->withCreator($creator ?? $this->owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
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
}
