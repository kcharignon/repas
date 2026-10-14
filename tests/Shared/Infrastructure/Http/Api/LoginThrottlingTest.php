<?php

namespace Repas\Tests\Shared\Infrastructure\Http\Api;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `login_throttling` (config/packages/security.yaml) : 5 échecs par 15 minutes pour un couple IP + email, puis 429 avec
 * `Retry-After`, même avec le bon mot de passe. Un autre email n'est pas touché. L'état du limiteur est en mémoire en
 * test : le client garde le même noyau d'une requête à l'autre.
 */
class LoginThrottlingTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
        $repository = static::getContainer()->get(UserRepository::class);
        $repository->save(new UserBuilder()->withEmail('alexiane@example.com')->withPassword(password_hash('test', PASSWORD_BCRYPT, ['cost' => 4]))->build());
        $repository->save(new UserBuilder()->withEmail('autre@example.com')->withPassword(password_hash('test', PASSWORD_BCRYPT, ['cost' => 4]))->build());
    }

    public function testTheSixthAttemptIsRefusedEvenWithTheRightPassword(): void
    {
        // When : cinq échecs
        for ($i = 1; $i <= 5; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "échec $i");
        }
        $this->login('alexiane@example.com', 'test');

        // Then
        $response = $this->client->getResponse();
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Too many failed login attempts, please try again later.']], $this->jsonBody());
        $this->assertGreaterThanOrEqual(60, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(900, (int) $response->headers->get('Retry-After'));
    }

    public function testAnotherEmailIsNotAffected(): void
    {
        // Given
        for ($i = 1; $i <= 6; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
        }
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());

        // When
        $this->login('autre@example.com', 'test');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('token', $this->jsonBody());
    }

    public function testAnUnknownEmailIsThrottledLikeAKnownOne(): void
    {
        // When : le plafond ne révèle pas si le compte existe
        for ($i = 1; $i <= 5; $i++) {
            $this->login('inconnu@example.com', 'mauvais');
        }
        $this->login('inconnu@example.com', 'mauvais');

        // Then
        $this->assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testASuccessfulLoginBeforeTheLimitIsNotCounted(): void
    {
        // When : quatre échecs, une connexion réussie remet le compteur à zéro, quatre échecs de plus
        for ($i = 1; $i <= 4; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
        }
        $this->login('alexiane@example.com', 'test');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        for ($i = 1; $i <= 4; $i++) {
            $this->login('alexiane@example.com', 'mauvais');
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        }
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
