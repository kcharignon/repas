<?php

namespace Repas\Tests\Shared\Infrastructure\Http\Api;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Firewalls `login` et `api` (ADR 0008). Chaque test crée ses utilisateurs (mot de passe connu) : aucune fixture.
 * `/api/v1/me` est la route protégée ROLE_USER, `/api/v1/conversions` la route réservée aux administrateurs.
 */
class JwtAuthenticationTest extends DatabaseTestCase
{
    private const string PASSWORD = 'correct-horse-battery';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::getContainer()->get('test.client');
    }

    public function testLoginReturnsATokenForValidCredentials(): void
    {
        // Given
        $user = $this->savedUser();

        // When
        $this->login($user->getEmail(), self::PASSWORD);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        $this->assertArrayHasKey('token', $body);
        $this->assertNotEmpty($body['token']);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        // Given
        $user = $this->savedUser();

        // When
        $this->login($user->getEmail(), 'not-the-password');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid credentials.']], $this->jsonBody());
    }

    public function testLoginRejectsUnknownUser(): void
    {
        // When
        $this->login('nobody@example.com', 'whatever');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid credentials.']], $this->jsonBody());
    }

    public function testLoginRejectsDisabledUserWithTheSameResponse(): void
    {
        // Given
        $user = $this->savedUser(disabled: true);

        // When
        $this->login($user->getEmail(), self::PASSWORD);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid credentials.']], $this->jsonBody());
    }

    public function testProtectedRouteWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/me');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
    }

    public function testProtectedRouteWithInvalidTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer not.a.token']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid token.']], $this->jsonBody());
    }

    public function testTokenObtainedByLoginIdentifiesTheUserOnMe(): void
    {
        // Given : chemin réel de bout en bout (firewalls, bus, handler, loader), sans double
        $user = $this->savedUser();
        $token = $this->tokenFor($user->getEmail(), self::PASSWORD);

        // When
        $this->client->request('GET', '/api/v1/me', server: $this->bearer($token));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => ['ROLE_USER'],
            'isAdmin' => false,
            'defaultServing' => 4,
        ], $this->jsonBody());
    }

    public function testAuthenticatedUserWithoutTheRoleGets403(): void
    {
        // Given
        $user = $this->savedUser();
        $token = $this->tokenFor($user->getEmail(), self::PASSWORD);

        // When
        $this->client->request('GET', '/api/v1/conversions', server: $this->bearer($token));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Forbidden']], $this->jsonBody());
    }

    public function testAdminTokenGrantsTheAdminRoute(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $token = $this->tokenFor($admin->getEmail(), self::PASSWORD);

        // When
        $this->client->request('GET', '/api/v1/conversions', server: $this->bearer($token));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testHealthStaysPublic(): void
    {
        // When
        $this->client->request('GET', '/api/v1/health');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testApiDocumentationStaysPublic(): void
    {
        // When
        $this->client->request('GET', '/api/doc.json');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('/api/login_check', $this->jsonBody()['paths']);
    }

    public function testWebSessionFirewallIsUnchanged(): void
    {
        // When
        $this->client->request('GET', '/login');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testWebRouteStillRedirectsAnonymousUsersToTheLoginForm(): void
    {
        // When
        $this->client->request('GET', '/shopping-list');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }

    private function savedUser(bool $admin = false, bool $disabled = false): User
    {
        $builder = new UserBuilder()->withPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        if ($admin) {
            $builder->isAdmin();
        }
        if ($disabled) {
            $builder->isDisabled();
        }
        $user = $builder->build();
        self::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/login_check',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );
    }

    private function tokenFor(string $email, string $password): string
    {
        $this->login($email, $password);

        return $this->jsonBody()['token'];
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
