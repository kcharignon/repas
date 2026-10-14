<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parcours des endpoints d'écriture des référentiels (unités, rayons, types de recette) avec les vrais bus et la vraie
 * base : création relue par son `Location` (vrai bus de lecture), doublon de slug, remplacement, droits.
 */
class ReferenceDataWriteApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
    }

    /**
     * @param array<string, mixed> $created
     * @param array<string, mixed> $replaced
     * @param array<string, mixed> $expectedAfterReplace
     */
    #[DataProvider('resources')]
    public function testAnAdministratorCreatesReadsAndReplaces(string $collection, array $created, string $slug, array $replaced, array $expectedAfterReplace): void
    {
        // Given
        $admin = $this->savedUser(admin: true);

        // When : création, puis relecture par le Location
        $this->request('POST', "/api/v1/$collection", $admin, $created);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame("/api/v1/$collection/$slug", $this->client->getResponse()->headers->get('Location'));
        $this->request('GET', "/api/v1/$collection/$slug", $admin);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame($slug, $this->jsonBody()['slug']);

        // When : remplacement (le slug ne change pas)
        $this->request('PUT', "/api/v1/$collection/$slug", $admin, $replaced);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/$collection/$slug", $admin);
        $body = $this->jsonBody();
        foreach ($expectedAfterReplace as $key => $value) {
            $this->assertEquals($value, $body[$key], $key);
        }
        $this->assertSame($slug, $body['slug']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, array<string, mixed>, array<string, mixed>}>
     */
    public static function resources(): iterable
    {
        yield 'unité' => ['units', ['name' => 'Pincée', 'symbol' => 'pin'], 'pincee', ['name' => 'Grosse pincée', 'symbol' => 'gpin'], ['name' => 'Grosse pincée', 'symbol' => 'gpin']];
        yield 'rayon' => ['departments', ['name' => 'Surgelés', 'image' => 'images/frozen.svg'], 'surgeles', ['name' => 'Glaces', 'image' => 'https://exemple.org/glaces.png'], ['name' => 'Glaces', 'image' => 'https://exemple.org/glaces.png']];
        yield 'type de recette' => ['recipe-types', ['name' => 'Apéritif', 'image' => 'images/aperitif.svg', 'order' => 4], 'aperitif', ['name' => 'Entrée froide', 'image' => 'images/aperitif.svg', 'order' => 2], ['name' => 'Entrée froide', 'order' => 2]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('existingOnes')]
    public function testCreatingOneWhoseSlugAlreadyExistsIsAConflictAndKeepsTheFirst(string $collection, array $existing, array $body): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $this->request('POST', "/api/v1/$collection", $admin, $existing);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('POST', "/api/v1/$collection", $admin, $body);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->request('GET', $this->client->getRequest()->getPathInfo() . '/' . $this->slugOf($existing['name']), $admin);
        $this->assertSame($existing['name'], $this->jsonBody()['name']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function existingOnes(): iterable
    {
        yield 'unité' => ['units', ['name' => 'Pincée', 'symbol' => 'pin'], ['name' => 'pincee', 'symbol' => 'autre']];
        yield 'rayon' => ['departments', ['name' => 'Surgelés', 'image' => 'images/frozen.svg'], ['name' => 'surgeles', 'image' => 'images/autre.svg']];
        yield 'type de recette' => ['recipe-types', ['name' => 'Apéritif', 'image' => 'images/aperitif.svg', 'order' => 4], ['name' => 'aperitif', 'image' => 'images/autre.svg', 'order' => 9]];
    }

    public function testReplacingAnUnknownOneIsNotFound(): void
    {
        // When & Then
        $admin = $this->savedUser(admin: true);
        $this->request('PUT', '/api/v1/units/inconnue', $admin, ['name' => 'x', 'symbol' => 'x']);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/departments/inconnu', $admin, ['name' => 'x', 'image' => 'images/x.svg']);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/recipe-types/inconnu', $admin, ['name' => 'x', 'image' => 'images/x.svg', 'order' => 1]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAUserCanReadButNotWrite(): void
    {
        // Given
        $this->persister->saveUnit(new UnitBuilder()->isGramme()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $user = $this->savedUser();

        // When & Then
        $this->request('GET', '/api/v1/units/gramme', $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/units/gramme', $user, ['name' => 'Hack', 'symbol' => 'h']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/departments', $user, ['name' => 'Hack', 'image' => 'images/h.svg']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', '/api/v1/recipe-types/plat', $user, ['name' => 'Hack', 'image' => 'images/h.svg', 'order' => 1]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/units/gramme', $user);
        $this->assertSame('gramme', $this->jsonBody()['name']);
    }

    private function slugOf(string $name): string
    {
        return StringTool::slugify($name);
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
