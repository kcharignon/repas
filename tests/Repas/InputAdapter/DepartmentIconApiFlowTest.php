<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * L'icône d'un rayon (ING-016 B) avec les vrais bus, la vraie base et le vrai JWT : l'administrateur la règle (création et remplacement), elle
 * est relue partout où un rayon est lu, `null` l'efface, une clé absente ne la touche pas (un ancien client), une valeur inconnue est refusée
 * sans rien écrire, et un utilisateur ordinaire ne règle rien (aucun accès de plus que les autres champs du rayon).
 */
class DepartmentIconApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveDepartment(new DepartmentBuilder()->withSlug('surgele')->withName('Surgelé')->withImage('images/surgele.png')->build());
        $this->admin = $this->savedUser(admin: true);
        $this->user = $this->savedUser();
    }

    public function testTheAdministratorSetsReadsChangesKeepsAndErasesTheIcon(): void
    {
        // When : une icône, relue partout où un rayon est lu
        $this->request('PUT', '/api/v1/departments/surgele', $this->admin, ['name' => 'Surgelé', 'image' => 'images/surgele.png', 'icon' => 'snowflake']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : la fiche, la liste, et le rayon d'une liste d'ingrédients (lus par un utilisateur ordinaire)
        $this->assertSame('snowflake', $this->read('/api/v1/departments/surgele')['icon']);
        $listed = array_values(array_filter($this->read('/api/v1/departments')['member'], static fn(array $department): bool => $department['slug'] === 'surgele'));
        $this->assertSame(['slug' => 'surgele', 'name' => 'Surgelé', 'image' => 'images/surgele.png', 'icon' => 'snowflake'], $listed[0]);
        $this->assertSame('snowflake', $this->read('/api/v1/departments/surgele/ingredients')['department']['icon']);

        // When : le nom change, la clé `icon` est absente (un ancien client)
        $this->request('PUT', '/api/v1/departments/surgele', $this->admin, ['name' => 'Surgelés', 'image' => 'images/surgele.png']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : l'icône est restée
        $this->assertSame(['Surgelés', 'snowflake'], [$this->read('/api/v1/departments/surgele')['name'], $this->read('/api/v1/departments/surgele')['icon']]);

        // When : une autre icône, puis `null`
        $this->request('PUT', '/api/v1/departments/surgele', $this->admin, ['name' => 'Surgelés', 'image' => 'images/surgele.png', 'icon' => 'can']);
        $this->assertSame('can', $this->read('/api/v1/departments/surgele')['icon']);
        $this->request('PUT', '/api/v1/departments/surgele', $this->admin, ['name' => 'Surgelés', 'image' => 'images/surgele.png', 'icon' => null]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : effacée, la clé est toujours écrite
        $body = $this->read('/api/v1/departments/surgele');
        $this->assertArrayHasKey('icon', $body);
        $this->assertNull($body['icon']);
    }

    public function testAnUnknownIconIsRefusedWithoutChangingAnything(): void
    {
        // Given
        $this->request('PUT', '/api/v1/departments/surgele', $this->admin, ['name' => 'Surgelé', 'image' => 'images/surgele.png', 'icon' => 'can']);

        // When
        $this->request('PUT', '/api/v1/departments/surgele', $this->admin, ['name' => 'Autre nom', 'image' => 'images/surgele.png', 'icon' => 'sparkles']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame(['Surgelé', 'can'], [$this->read('/api/v1/departments/surgele')['name'], $this->read('/api/v1/departments/surgele')['icon']]);
    }

    public function testAnAdministratorCreatesADepartmentWithItsIcon(): void
    {
        // When
        $this->request('POST', '/api/v1/departments', $this->admin, ['name' => 'Conserves', 'image' => 'images/conserves.png', 'icon' => 'can']);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame('can', $this->read($this->client->getResponse()->headers->get('Location'))['icon']);
    }

    public function testAnOrdinaryUserCannotSetTheIcon(): void
    {
        // When
        $this->request('PUT', '/api/v1/departments/surgele', $this->user, ['name' => 'Surgelé', 'image' => 'images/surgele.png', 'icon' => 'snowflake']);
        $forbiddenPut = $this->client->getResponse()->getStatusCode();
        $this->request('POST', '/api/v1/departments', $this->user, ['name' => 'Conserves', 'image' => 'images/conserves.png', 'icon' => 'can']);

        // Then : comme les autres champs du rayon, réservé aux administrateurs ; rien n'a été écrit
        $this->assertSame([403, 403], [$forbiddenPut, $this->client->getResponse()->getStatusCode()]);
        $this->assertNull($this->read('/api/v1/departments/surgele')['icon']);
    }

    public function testTheDepartmentOfAnIngredientOfTheUserIsReadWithItsIcon(): void
    {
        // Given : un ingrédient de l'utilisateur dans le rayon, dont l'icône est réglée
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Petits pois')->withSlug('petits-pois-u')->withDepartment(new DepartmentBuilder()->withSlug('surgele')->withName('Surgelé')->withImage('images/surgele.png'))->withCreator($this->user)->build());
        $this->request('PUT', '/api/v1/departments/surgele', $this->admin, ['name' => 'Surgelé', 'image' => 'images/surgele.png', 'icon' => 'snowflake']);

        // When
        $body = $this->read('/api/v1/departments/surgele/ingredients', $this->user);

        // Then : le rayon dit son icône, ses lignes ne la répètent pas
        $this->assertSame('snowflake', $body['department']['icon']);
        $this->assertSame(['slug', 'name', 'purchaseUnitSymbol', 'canEdit', 'isBase'], array_keys($body['member'][0]));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $uri, ?User $as = null): array
    {
        $this->request('GET', $uri, $as ?? $this->user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $uri);

        return $this->jsonBody();
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

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
