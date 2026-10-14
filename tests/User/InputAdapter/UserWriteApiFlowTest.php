<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parcours des endpoints d'écriture de l'utilisateur avec les vrais bus et la vraie base : inscription publique relue
 * par son `Location`, connexion par /api/login_check, doublon d'email, réglages et changement de mot de passe.
 */
class UserWriteApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testAVisitorRegistersLogsInAndReadsHisAccount(): void
    {
        // When : inscription sans jeton
        $this->request('POST', '/api/v1/users', body: ['email' => 'alexiane@example.com', 'password' => 'un-mot-de-passe', 'defaultServing' => 3]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertMatchesRegularExpression('#^/api/v1/users/[0-9a-f-]{36}$#', $location);

        // When : connexion puis relecture par le Location
        $token = $this->tokenFor('alexiane@example.com', 'un-mot-de-passe');
        $this->request('GET', $location, $token);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['email' => 'alexiane@example.com', 'roles' => ['ROLE_USER'], 'isAdmin' => false, 'defaultServing' => 3], array_diff_key($this->jsonBody(), ['id' => true]));
        $this->assertSame($location, '/api/v1/users/' . $this->jsonBody()['id']);
    }

    public function testRegisteringTwiceWithTheSameEmailGivesAConflictAndKeepsTheFirstAccount(): void
    {
        // Given
        $this->request('POST', '/api/v1/users', body: ['email' => 'alexiane@example.com', 'password' => 'un-mot-de-passe', 'defaultServing' => 3]);

        // When
        $this->request('POST', '/api/v1/users', body: ['email' => 'alexiane@example.com', 'password' => 'autre-mot-de-passe', 'defaultServing' => 9]);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('alexiane@example.com');
        $this->assertSame(3, $user->getDefaultServing());
        $this->assertTrue($user->passwordMatch('un-mot-de-passe'));
    }

    public function testTheServingChangesWithoutTheCurrentPasswordAndThePasswordStaysTheSame(): void
    {
        // Given
        $this->savedUser('alexiane@example.com');
        $token = $this->tokenFor('alexiane@example.com', 'test');

        // When
        $this->request('PUT', '/api/v1/me/settings', $token, ['defaultServing' => 7]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/me', $token);
        $this->assertSame(7, $this->jsonBody()['defaultServing']);
        $this->login('alexiane@example.com', 'test');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testThePasswordChangesWithTheCurrentOne(): void
    {
        // Given
        $this->savedUser('alexiane@example.com');
        $token = $this->tokenFor('alexiane@example.com', 'test');

        // When
        $this->request('PUT', '/api/v1/me/settings', $token, ['defaultServing' => 4, 'newPassword' => 'un-nouveau-mot-de-passe', 'actualPassword' => 'test']);

        // Then : l'ancien mot de passe ne connecte plus, le nouveau oui
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->login('alexiane@example.com', 'test');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->login('alexiane@example.com', 'un-nouveau-mot-de-passe');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('wrongCurrentPasswords')]
    public function testAWrongOrMissingCurrentPasswordChangesNothing(array $body): void
    {
        // Given
        $this->savedUser('alexiane@example.com');
        $token = $this->tokenFor('alexiane@example.com', 'test');

        // When
        $this->request('PUT', '/api/v1/me/settings', $token, ['defaultServing' => 9, 'newPassword' => 'un-nouveau-mot-de-passe'] + $body);

        // Then : ni le mot de passe ni le nombre de personnes ne bougent
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('alexiane@example.com');
        $this->assertSame(4, $user->getDefaultServing());
        $this->assertTrue($user->passwordMatch('test'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function wrongCurrentPasswords(): iterable
    {
        yield 'mot de passe actuel absent' => [[]];
        yield 'mot de passe actuel erroné' => [['actualPassword' => 'pas-le-bon']];
    }

    public function testAUserCannotReadAnotherAccountButAnAdministratorCan(): void
    {
        // Given
        $other = $this->savedUser('other@example.com');
        $this->savedUser('alexiane@example.com');
        $this->savedUser('admin@example.com', admin: true);

        // When
        $this->request('GET', '/api/v1/users/' . $other->getId(), $this->tokenFor('alexiane@example.com', 'test'));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('GET', '/api/v1/users/' . $other->getId(), $this->tokenFor('admin@example.com', 'test'));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('other@example.com', $this->jsonBody()['email']);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?string $token = null, ?array $body = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: $server);
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }

    private function tokenFor(string $email, string $password): string
    {
        $this->login($email, $password);

        return $this->jsonBody()['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function savedUser(string $email, bool $admin = false): User
    {
        $builder = new UserBuilder()->withEmail($email);
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
