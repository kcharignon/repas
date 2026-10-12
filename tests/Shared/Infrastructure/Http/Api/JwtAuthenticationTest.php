<?php

namespace Repas\Tests\Shared\Infrastructure\Http\Api;


use Repas\Tests\Helper\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Firewalls `login` et `api` (ADR 0008) sur la base de fixtures :
 * alexiane (ROLE_USER, actif), kantin (ROLE_ADMIN), john (désactivé).
 */
class JwtAuthenticationTest extends DatabaseTestCase
{
    private const string USER_EMAIL = 'alexiane.sichi@gmail.com';
    private const string USER_PASSWORD = 'logan';
    private const string ADMIN_EMAIL = 'kantincharignon@gmail.com';
    private const string ADMIN_PASSWORD = 'test';
    private const string DISABLED_EMAIL = 'john.doe@gmail.com';
    private const string DISABLED_PASSWORD = 'hjfauendfiwp';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::getContainer()->get('test.client');
    }

    public function testLoginReturnsATokenForValidCredentials(): void
    {
        // When
        $this->login(self::USER_EMAIL, self::USER_PASSWORD);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        $this->assertArrayHasKey('token', $body);
        $this->assertNotEmpty($body['token']);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        // When
        $this->login(self::USER_EMAIL, 'not-the-password');

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
        // When
        $this->login(self::DISABLED_EMAIL, self::DISABLED_PASSWORD);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid credentials.']], $this->jsonBody());
    }

    public function testProtectedRouteWithoutTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/_probe/whoami');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Authentication required.']], $this->jsonBody());
    }

    public function testProtectedRouteWithInvalidTokenIsRefused(): void
    {
        // When
        $this->client->request('GET', '/api/v1/_probe/whoami', server: ['HTTP_AUTHORIZATION' => 'Bearer not.a.token']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid token.']], $this->jsonBody());
    }

    public function testProtectedRouteWithValidTokenIsGranted(): void
    {
        // Given
        $token = $this->tokenFor(self::USER_EMAIL, self::USER_PASSWORD);

        // When
        $this->client->request('GET', '/api/v1/_probe/whoami', server: $this->bearer($token));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['identifier' => self::USER_EMAIL], $this->jsonBody());
    }

    public function testAuthenticatedUserWithoutTheRoleGets403(): void
    {
        // Given
        $token = $this->tokenFor(self::USER_EMAIL, self::USER_PASSWORD);

        // When
        $this->client->request('GET', '/api/v1/_probe/admin', server: $this->bearer($token));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Forbidden']], $this->jsonBody());
    }

    public function testAdminTokenGrantsTheAdminRoute(): void
    {
        // Given
        $token = $this->tokenFor(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        // When
        $this->client->request('GET', '/api/v1/_probe/admin', server: $this->bearer($token));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['admin' => true], $this->jsonBody());
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
